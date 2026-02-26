# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

"""
Démon NUT Free — nutfreed.py
Connexion TCP directe vers les serveurs NUT (mode local uniquement).
Le mode SSH distant est géré entièrement par PHP et SSH-Manager.

Communication :
  PHP → daemon : JSON via socket TCP (127.0.0.1:socketport)
  daemon → PHP : HTTP POST vers callback URL (jeeNut_free.php)

Actions reçues via socket :
  update_devices  : remplace toute la liste des équipements à surveiller
  add_device      : ajoute/met à jour un équipement
  remove_device   : supprime un équipement de la liste de polling
  query_now       : force une interrogation immédiate d'un équipement
  shutdown        : arrête le daemon proprement
"""

import logging
import sys
from pathlib import Path
import socket
import time
import signal
import json
import argparse
import threading
from dataclasses import dataclass
from typing import Any

# --- Import pynutclient ---
try:
    from PyNUTClient.PyNUT import PyNUTClient
except ImportError as e:
    print(f'[DAEMON][IMPORT] ERREUR : pynutclient introuvable :: {e}')
    sys.exit(1)

# --- Import Jeedom lib ---
try:
    from jeedom.jeedom import jeedom_utils, jeedom_com, jeedom_socket, JEEDOM_SOCKET_MESSAGE
except ImportError as e:
    print(f'[DAEMON][IMPORT] ERREUR : jeedom lib introuvable :: {e}')
    sys.exit(1)

# --- Import Config ---
from utils import Config, Comm

# ---------------------------------------------------------------------------
# Commandes statiques Jeedom — set minimal garanti sur tous les onduleurs :
#   refresh, ups_status, ups_status_label, ups_load, battery_charge,
#   battery_runtime (+battery_runtime_min dérivé), device_mfr, device_model,
#   ups_serial, cmd_result.
# Le polling query_device() couvre TOUTES les vars via le catalogue nut_vars.json ;
# les commandes dynamiques découvertes sont ainsi aussi tenues à jour en continu.
# ---------------------------------------------------------------------------


# ---------------------------------------------------------------------------

@dataclass
class NutDevice:
    eqLogicId: str
    name: str          # nom lisible de l'équipement Jeedom
    host: str
    port: int
    upsName: str       # vide = auto-détection via GetUPSList()
    autoDetect: bool
    nutLogin: str | None = None    # login upsd (None = pas d'authentification)
    nutPassword: str | None = None  # mot de passe upsd (None = pas d'authentification)
    resolvedUpsName: str | None = None  # nom résolu et mis en cache après la première détection

    @classmethod
    def from_dict(cls, d: dict[str, Any]) -> 'NutDevice':
        login = str(d.get('nutLogin', '')).strip() or None
        password = str(d.get('nutPassword', '')).strip() or None
        return cls(
            eqLogicId=str(d['eqLogicId']),
            name=str(d.get('eqName', d.get('eqLogicId', ''))),
            host=str(d.get('host', '127.0.0.1')),
            port=int(d.get('port', 3493)),
            upsName=str(d.get('upsName', '')).strip(),
            autoDetect=bool(int(d.get('autoDetect', '1'))),
            nutLogin=login,
            nutPassword=password,
            resolvedUpsName=None,
        )


# ---------------------------------------------------------------------------

def _resolve_ups_name(device: NutDevice) -> str | None:
    """
    Retourne le nom UPS effectif pour ce device.
    Si déjà résolu (cache), retourne directement sans connexion.
    Sinon appelle GetUPSList() via PyNUTClient et met en cache le résultat.
    """
    if device.resolvedUpsName:
        return device.resolvedUpsName

    # Nom statique configuré : pas besoin de détection
    if not device.autoDetect and device.upsName:
        device.resolvedUpsName = device.upsName
        return device.resolvedUpsName

    # Auto-détection via LIST UPS
    try:
        client = PyNUTClient(host=device.host, port=device.port,
                             login=device.nutLogin, password=device.nutPassword)
        upsList = client.GetUPSList()
        if not upsList:
            logging.warning('[DAEMON][%s] Aucun UPS trouvé sur %s:%d',
                            device.name, device.host, device.port)
            return None
        first_key = next(iter(upsList))
        upsName = first_key.decode('ascii') if isinstance(first_key, bytes) else first_key
        device.resolvedUpsName = upsName
        logging.debug('[DAEMON][%s] UPS auto-détecté et mis en cache : %s', device.name, upsName)
        return device.resolvedUpsName
    except Exception as e:
        logging.error('[DAEMON][%s] _resolve_ups_name erreur :: %s', device.name, e)
        return None


def _recv_line(s: socket.socket) -> bytes:
    """Accumule les octets jusqu'au premier \\n (réponse NUT complète).
    Défini au niveau module pour éviter une ré-allocation à chaque appel.
    """
    buf = b''
    while b'\n' not in buf:
        chunk = s.recv(256)
        if not chunk:
            break
        buf += chunk
    return buf


def _nut_get_var(host: str, port: int, upsName: str, varName: str,
                 login: str | None = None, password: str | None = None,
                 timeout: float = 5.0) -> str | None:
    """
    Lecture directe d'une seule variable NUT via le protocole brut.
    Envoie : GET VAR <ups> <var>\n
    Attend  : VAR <ups> <var> "<value>"\n
    Supporte l'authentification upsd (USERNAME / PASSWORD) si fournie.
    Beaucoup plus léger que PyNUTClient + LIST VAR (toutes les vars).
    """
    try:
        with socket.create_connection((host, port), timeout=timeout) as sock:
            if login:
                sock.sendall(f'USERNAME {login}\n'.encode('ascii'))
                _recv_line(sock)  # attend OK\n
            if password:
                sock.sendall(f'PASSWORD {password}\n'.encode('ascii'))
                _recv_line(sock)  # attend OK\n
            sock.sendall(f'GET VAR {upsName} {varName}\n'.encode('ascii'))
            buf = _recv_line(sock)
        line = buf.split(b'\n')[0].decode('ascii').strip()
        # Réponse attendue : VAR <ups> <var> "<value>"
        if line.startswith('VAR '):
            parts = line.split('"')
            return parts[1] if len(parts) >= 2 else None
        logging.debug('[WATCHER] _nut_get_var réponse inattendue : %s', line)
        return None
    except Exception as e:
        logging.debug('[WATCHER] _nut_get_var %s:%d %s=%s :: %s', host, port, upsName, varName, e)
        return None


def get_ups_status_label(device: NutDevice) -> str | None:
    """
    Lecture légère de ups.status uniquement via GET VAR (protocole brut).
    Utilisé par le StatusWatcher pour détecter les changements d'état.
    Retourne la valeur brute (ex: 'OL', 'OB LB') ou None en cas d'erreur.
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        return None
    return _nut_get_var(device.host, device.port, upsName, 'ups.status',
                        login=device.nutLogin, password=device.nutPassword)


def query_device(device: NutDevice) -> dict[str, str] | None:
    """
    Interroge un serveur NUT et retourne {logicalId: valeur} ou None.
    Couvre TOUTES les variables retournées par l'UPS (statiques + dynamiques
    découvertes), mappées via le catalogue nut_vars.json.
    Seules les valeurs brutes sont envoyées ; toute dérivation (_min, labels)
    est traitée côté PHP par le mécanisme derivedFrom (jeeNut_free.php).
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        logging.warning('[DAEMON][%s] Nom UPS non résolu, poll ignoré', device.name)
        return None

    try:
        client = PyNUTClient(host=device.host, port=device.port,
                             login=device.nutLogin, password=device.nutPassword)
    except Exception as e:
        logging.error('[DAEMON][%s] Connexion NUT %s:%d impossible :: %s',
                      device.name, device.host, device.port, e)
        return None

    try:
        allVarsRaw = client.GetUPSVars(upsName)
        allVars: dict[str, str] = {_decode(k): _decode(v) for k, v in allVarsRaw.items()}
    except Exception as e:
        logging.error('[DAEMON][%s] GetUPSVars(%s) erreur :: %s', device.name, upsName, e)
        return None

    catalog = _load_nut_vars()
    known_vars = catalog.get('vars', {})

    results: dict[str, str] = {}
    for nut_var, raw in allVars.items():
        value = raw.strip()
        entry = known_vars.get(nut_var, {})
        logicalId = entry.get('logicalId', nut_var.replace('.', '_'))

        results[logicalId] = value
        logging.debug('[DAEMON][%s] %s = %s', device.name, logicalId, value)

    logging.info('[DAEMON][%s] %d valeur(s) collectées', device.name, len(results))
    return results


def _run_instcmd(device: NutDevice, nutInstCmd: str) -> None:
    """
    Exécute une commande instcmd NUT (beeper.disable, test.battery.start.quick, etc.)
    via PyNUTClient.RunUPSCommand, puis renvoie le résultat à Jeedom via le callback.
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        result = f'{nutInstCmd} → ERR: nom UPS non résolu'
        logging.error('[DAEMON][%s] instcmd %s :: UPS non résolu', device.name, nutInstCmd)
    else:
        try:
            client = PyNUTClient(host=device.host, port=device.port,
                                 login=device.nutLogin, password=device.nutPassword)
            client.RunUPSCommand(upsName, nutInstCmd)
            result = f'{nutInstCmd} → OK'
            logging.info('[DAEMON][%s] instcmd %s :: OK', device.name, nutInstCmd)
        except Exception as e:
            result = f'{nutInstCmd} → ERR: {e}'
            logging.error('[DAEMON][%s] instcmd %s :: %s', device.name, nutInstCmd, e)
    # Renvoi du résultat à Jeedom (commande cmd_result) via le callback standard
    Comm.sendToJeedom.send_change_immediate({
        'update': {device.eqLogicId: {'cmd_result': result}}
    })


def _run_list_query_all(device: NutDevice) -> None:
    """
    Récupère instcmds ET RW vars en une seule connexion NUT,
    puis envoie deux messages list_result distincts à Jeedom.
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        logging.error('[DAEMON][%s] list_query_all :: UPS non résolu', device.name)
        for qtype in ('instcmds', 'rwvars'):
            Comm.sendToJeedom.send_change_immediate({
                'list_result': {device.eqLogicId: {'type': qtype, 'result': 'ERR: nom UPS non résolu'}}
            })
        return
    try:
        client = PyNUTClient(host=device.host, port=device.port,
                             login=device.nutLogin, password=device.nutPassword)
        raw_cmds = client.GetUPSCommands(upsName) or {}
        raw_rw   = client.GetRWVars(upsName) or {}
    except Exception as e:
        logging.error('[DAEMON][%s] list_query_all :: %s', device.name, e)
        for qtype in ('instcmds', 'rwvars'):
            Comm.sendToJeedom.send_change_immediate({
                'list_result': {device.eqLogicId: {'type': qtype, 'result': f'ERR: {e}'}}
            })
        return
    cmds = sorted(_decode(k) for k in raw_cmds)
    result_cmds = '\n'.join(cmds) if cmds else '(aucune commande disponible)'
    lines = sorted(f'{_decode(k)} = {_decode(v)}' for k, v in raw_rw.items())
    result_rw = '\n'.join(lines) if lines else '(aucune variable RW disponible)'
    logging.info('[DAEMON][%s] list_query_all :: %d instcmds, %d rwvars',
                 device.name, len(raw_cmds), len(raw_rw))
    Comm.sendToJeedom.send_change_immediate({
        'list_result': {device.eqLogicId: {'type': 'instcmds', 'result': result_cmds}}
    })
    Comm.sendToJeedom.send_change_immediate({
        'list_result': {device.eqLogicId: {'type': 'rwvars', 'result': result_rw}}
    })


def _decode(v: Any) -> str:
    """Normalise bytes → str (réponses PyNUTClient)."""
    if isinstance(v, bytes):
        return v.decode('utf-8', errors='replace')
    return str(v) if v is not None else ''


def _load_nut_vars() -> dict[str, Any]:
    """
    Charge nut_vars.json en cache thread-safe (cache stocké dans Config).
    Retourne {'vars': {...}, 'instcmds': {...}} ou dict vide en cas d'erreur.
    """
    with Config.nutVarsLock:
        if Config.nutVarsCache is not None:
            return Config.nutVarsCache
        try:
            with open(Config.NUT_VARS_PATH, 'r', encoding='utf-8') as f:
                data: dict[str, Any] = json.load(f)
        except Exception as e:
            logging.error('[DAEMON][NUT_VARS] Impossible de charger nut_vars.json :: %s', e)
            data = {'vars': {}, 'instcmds': {}}
        Config.nutVarsCache = data
        logging.info('[DAEMON][NUT_VARS] Catalogue chargé (%d vars, %d instcmds)',
                     len(data.get('vars', {})),
                     len(data.get('instcmds', {})))
        return data


def _run_discover_all(device: NutDevice) -> None:
    """
    Découverte complète des capacités d'un UPS :
      - toutes les variables INFO supportées (GetUPSVars)
      - variables RW (GetRWVars) — subtype issu du catalogue nut_vars.json
      - commandes instcmd disponibles (GetUPSCommands)

    Enrichit chaque entrée avec les données du catalogue nut_vars.json (logicalId, name, unit,
    subtype, icon) et renvoie le résultat à Jeedom via le callback 'discover_result'.

    Format du payload envoyé :
    {
      'discover_result': {
        '<eqLogicId>': {
          'info_vars' : [{'nut_var', 'value', 'logicalId', 'name', 'unit', 'subtype', 'icon'}, ...],
          'rw_vars'   : [{'nut_var', 'value', 'logicalId', 'name', 'unit', 'subtype', 'icon'}, ...],
          'instcmds'  : [{'nut_cmd', 'logicalId', 'name', 'icon'}, ...]
        }
      }
    }
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        logging.error('[DAEMON][%s] discover_all :: UPS non résolu', device.name)
        Comm.sendToJeedom.send_change_immediate({
            'discover_result': {device.eqLogicId: {'error': 'UPS non résolu'}}
        })
        return

    try:
        client = PyNUTClient(host=device.host, port=device.port,
                             login=device.nutLogin, password=device.nutPassword)

        raw_all = client.GetUPSVars(upsName) or {}
        all_vars: dict[str, str] = {_decode(k): _decode(v) for k, v in raw_all.items()}

        raw_rw = client.GetRWVars(upsName) or {}
        rw_keys: set[str] = {_decode(k) for k in raw_rw.keys()}

        raw_cmds = client.GetUPSCommands(upsName) or {}
        cmds_list: list[str] = sorted(_decode(k) for k in raw_cmds.keys())

    except Exception as e:
        logging.error('[DAEMON][%s] discover_all :: erreur NUT :: %s', device.name, e)
        Comm.sendToJeedom.send_change_immediate({
            'discover_result': {device.eqLogicId: {'error': str(e)}}
        })
        return

    catalog = _load_nut_vars()
    known_vars = catalog.get('vars', {})
    known_cmds = catalog.get('instcmds', {})

    # --- info_vars : toutes les variables hors RW ---
    info_vars = []
    for nut_var, value in sorted(all_vars.items()):
        if nut_var in rw_keys:
            continue
        entry = known_vars.get(nut_var, {})
        info_vars.append({
            'nut_var': nut_var,
            'value': value,
            'logicalId': entry.get('logicalId', nut_var.replace('.', '_')),
            'name': entry.get('name', nut_var),
            'unit': entry.get('unit', ''),
            'subtype': entry.get('subtype', 'string'),
            'icon': entry.get('icon', 'fas fa-circle'),
        })

    # --- rw_vars : subtype issu du catalogue, 'string' par défaut ---
    rw_vars = []
    for nut_var in sorted(rw_keys):
        entry = known_vars.get(nut_var, {})
        rw_vars.append({
            'nut_var': nut_var,
            'value': all_vars.get(nut_var, ''),
            'logicalId': entry.get('logicalId', nut_var.replace('.', '_')),
            'name': entry.get('name', nut_var),
            'unit': entry.get('unit', ''),
            'subtype': entry.get('subtype', 'string'),
            'icon': entry.get('icon', 'fas fa-sliders-h icon_blue'),
        })

    # --- instcmds ---
    instcmds = []
    for nut_cmd in cmds_list:
        entry = known_cmds.get(nut_cmd, {})
        instcmds.append({
            'nut_cmd': nut_cmd,
            'logicalId': entry.get('logicalId', nut_cmd.replace('.', '_')),
            'name': entry.get('name', nut_cmd),
            'icon': entry.get('icon', 'fas fa-terminal icon_blue'),
        })

    logging.info('[DAEMON][%s] discover_all :: %d info_vars, %d rw_vars, %d instcmds',
                 device.name, len(info_vars), len(rw_vars), len(instcmds))

    Comm.sendToJeedom.send_change_immediate({
        'discover_result': {
            device.eqLogicId: {
                'info_vars': info_vars,
                'rw_vars': rw_vars,
                'instcmds': instcmds,
            }
        }
    })


def _run_setrwvar(device: NutDevice, nutRwVar: str, value: str) -> None:
    """
    Modifie une variable RW sur le serveur NUT via PyNUTClient.SetRWVar,
    puis renvoie la confirmation (ou l'erreur) à Jeedom via callback update (cmd_result).
    Met aussi à jour la commande info <logicalId> avec la nouvelle valeur brute ;
    toute dérivation (_min) est traitée côté PHP via derivedFrom (jeeNut_free.php).
    """
    upsName = _resolve_ups_name(device)
    if not upsName:
        result = f'{nutRwVar} → ERR: nom UPS non résolu'
        logging.error('[DAEMON][%s] setrwvar %s :: UPS non résolu', device.name, nutRwVar)
    else:
        try:
            client = PyNUTClient(host=device.host, port=device.port,
                                 login=device.nutLogin, password=device.nutPassword)
            client.SetRWVar(upsName, nutRwVar, value)
            result = f'{nutRwVar} → OK ({value})'
            logging.info('[DAEMON][%s] setrwvar %s = %s :: OK', device.name, nutRwVar, value)
            # Mettre à jour la commande info correspondante avec la nouvelle valeur
            catalog = _load_nut_vars()
            entry = catalog.get('vars', {}).get(nutRwVar, {})
            mapped_id = entry.get('logicalId', nutRwVar.replace('.', '_'))
            # Envoi valeur brute + cmd_result ; dérivation (_min…) assurée par PHP via derivedFrom
            Comm.sendToJeedom.send_change_immediate({
                'update': {device.eqLogicId: {mapped_id: value, 'cmd_result': result}}
            })
            return
        except Exception as e:
            result = f'{nutRwVar} → ERR: {e}'
            logging.error('[DAEMON][%s] setrwvar %s :: %s', device.name, nutRwVar, e)
    Comm.sendToJeedom.send_change_immediate({
        'update': {device.eqLogicId: {'cmd_result': result}}
    })


# ---------------------------------------------------------------------------

class Loops:

    # *** Boucle events from Jeedom ***
    @staticmethod
    def eventsFromJeedom(cycle=0.5):
        while not myConfig.IS_ENDING:
            if not JEEDOM_SOCKET_MESSAGE.empty():
                logging.debug('[DAEMON][SOCKET] Message reçu')

                try:
                    message = json.loads(JEEDOM_SOCKET_MESSAGE.get().decode('utf-8'))
                except Exception as e:
                    logging.error('[DAEMON][SOCKET] Erreur décodage JSON :: %s', e)
                    time.sleep(cycle)
                    continue

                if message.get('apikey') != myConfig.apiKey:
                    logging.error('[DAEMON][SOCKET] API key invalide, message ignoré')
                    time.sleep(cycle)
                    continue

                try:
                    action = message.get('action', '')
                    logging.info('[DAEMON][SOCKET] Action reçue : %s', action)

                    if action == 'update_devices':
                        Loops._stop_all_watchers()
                        with myConfig.devicesLock:
                            myConfig.devices = {}
                            for d in message.get('devices', []):
                                dev = NutDevice.from_dict(d)
                                myConfig.devices[dev.eqLogicId] = dev
                            devices_snapshot = dict(myConfig.devices)
                        for dev in devices_snapshot.values():
                            Loops._start_watcher(dev)
                        logging.info('[DAEMON] Liste mise à jour : %d équipement(s)', len(myConfig.devices))

                    elif action == 'add_device':
                        device_data = message.get('device')
                        if device_data:
                            device = NutDevice.from_dict(device_data)
                            with myConfig.devicesLock:
                                myConfig.devices[device.eqLogicId] = device
                            Loops._start_watcher(device)
                            logging.info('[DAEMON] Équipement ajouté/mis à jour : %s (id=%s) host=%s:%d',
                                         device.name, device.eqLogicId, device.host, device.port)

                    elif action == 'remove_device':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        Loops._stop_watcher(eqLogicId)
                        with myConfig.devicesLock:
                            myConfig.devices.pop(eqLogicId, None)
                        logging.info('[DAEMON] Équipement retiré : %s', eqLogicId)

                    elif action == 'query_now':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        with myConfig.devicesLock:
                            device = myConfig.devices.get(eqLogicId)
                        if device:
                            threading.Thread(target=Loops._poll_device, args=(device,), daemon=True).start()
                        else:
                            logging.warning('[DAEMON][SOCKET] query_now : équipement %s inconnu', eqLogicId)

                    elif action == 'instcmd':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        nutInstCmd = str(message.get('nutInstCmd', '')).strip()
                        with myConfig.devicesLock:
                            device = myConfig.devices.get(eqLogicId)
                        if not device:
                            logging.warning('[DAEMON][SOCKET] instcmd : équipement %s inconnu', eqLogicId)
                        elif not nutInstCmd:
                            logging.warning('[DAEMON][SOCKET] instcmd : nutInstCmd vide')
                        else:
                            threading.Thread(
                                target=_run_instcmd, args=(device, nutInstCmd), daemon=True
                            ).start()

                    elif action == 'list_query':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        with myConfig.devicesLock:
                            device = myConfig.devices.get(eqLogicId)
                        if not device:
                            logging.warning('[DAEMON][SOCKET] list_query : équipement %s inconnu', eqLogicId)
                        else:
                            threading.Thread(
                                target=_run_list_query_all, args=(device,), daemon=True
                            ).start()

                    elif action == 'discover_all':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        with myConfig.devicesLock:
                            device = myConfig.devices.get(eqLogicId)
                        if not device:
                            logging.warning('[DAEMON][SOCKET] discover_all : équipement %s inconnu', eqLogicId)
                        else:
                            threading.Thread(
                                target=_run_discover_all, args=(device,), daemon=True
                            ).start()

                    elif action == 'setrwvar':
                        eqLogicId = str(message.get('eqLogicId', ''))
                        nutRwVar  = str(message.get('nutRwVar', '')).strip()
                        value     = str(message.get('value', '')).strip()
                        with myConfig.devicesLock:
                            device = myConfig.devices.get(eqLogicId)
                        if not device:
                            logging.warning('[DAEMON][SOCKET] setrwvar : équipement %s inconnu', eqLogicId)
                        elif not nutRwVar:
                            logging.warning('[DAEMON][SOCKET] setrwvar : nutRwVar vide')
                        else:
                            threading.Thread(
                                target=_run_setrwvar, args=(device, nutRwVar, value), daemon=True
                            ).start()

                    elif action == 'shutdown':
                        logging.info('[DAEMON] Arrêt demandé via socket')
                        shutdown()

                    else:
                        logging.warning('[DAEMON][SOCKET] Action inconnue : %s', action)

                except Exception as e:
                    logging.error('[DAEMON][SOCKET] Erreur traitement message :: %s', e)

            time.sleep(cycle)

    # *** Boucle principale ***
    @staticmethod
    def mainLoop(cycle=0.5):
        my_jeedom_socket.open()
        logging.info('[DAEMON][MAINLOOP] Démarrage MainLoop')

        # Thread pour les events venant de Jeedom
        threading.Thread(target=Loops.eventsFromJeedom, args=(myConfig.cycleEvent,), daemon=True).start()

        # Informer Jeedom que le daemon est démarré
        Comm.sendToJeedom.send_change_immediate({'daemonStarted': '1'})
        logging.info('[DAEMON][MAINLOOP] daemonStarted envoyé à Jeedom')

        lastPoll = 0.0
        while not myConfig.IS_ENDING:
            currentTime = int(time.time())

            # Heartbeat
            if (myConfig.heartbeatLastTime + myConfig.heartbeatFrequency) <= currentTime:
                logging.info('[DAEMON][MAINLOOP] Heartbeat = 1')
                Comm.sendToJeedom.send_change_immediate({'heartbeat': '1'})
                myConfig.heartbeatLastTime = currentTime

            if currentTime - lastPoll >= myConfig.cyclePolling:
                lastPoll = currentTime
                with myConfig.devicesLock:
                    devices = dict(myConfig.devices)
                if devices:
                    for device in devices.values():
                        threading.Thread(
                            target=Loops._poll_device, args=(device,), daemon=True
                        ).start()
                else:
                    logging.debug('[DAEMON] Aucun équipement enregistré, polling ignoré')
            time.sleep(cycle)

        logging.info('[DAEMON][MAINLOOP] MainLoop terminée')

    # *** StatusWatcher — surveillance ups.status par équipement (upsmon-style) ***
    @staticmethod
    def statusWatcher(device: NutDevice, stop_event: threading.Event) -> None:
        """
        Thread par équipement. Surveille ups.status toutes les cycleWatcher secondes.
        Sur changement détecté → déclenche un poll complet immédiat.
        Cycle adaptatif : cycleWatcherAlert (2s) si OB, cycleWatcher (5s) sinon.
        """
        logging.info('[WATCHER][%s] Démarrage surveillance statut (cycle=%ss / alert=%ss)',
                     device.name, myConfig.cycleWatcher, myConfig.cycleWatcherAlert)
        current_cycle = myConfig.cycleWatcher
        first_poll = True

        while not stop_event.is_set() and not myConfig.IS_ENDING:
            status = get_ups_status_label(device)

            if status is not None:
                last = myConfig.deviceLastStatus.get(device.eqLogicId, '')
                if status != last:
                    if first_poll:
                        logging.info('[WATCHER][%s] Statut initial : %s', device.name, status)
                    else:
                        logging.info('[WATCHER][%s] Changement statut : \'%s\' → \'%s\'',
                                     device.name, last, status)
                        threading.Thread(
                            target=Loops._poll_device, args=(device,), daemon=True
                        ).start()
                    myConfig.deviceLastStatus[device.eqLogicId] = status
                    first_poll = False

                # Cycle adaptatif : réduit si sur batterie
                current_cycle = myConfig.cycleWatcherAlert if 'OB' in status.upper() else myConfig.cycleWatcher
            else:
                current_cycle = myConfig.cycleWatcher  # erreur connexion → cycle normal

            stop_event.wait(current_cycle)

        logging.info('[WATCHER][%s] Arrêt surveillance statut', device.name)

    @staticmethod
    def _start_watcher(device: NutDevice) -> None:
        """Démarre un thread StatusWatcher pour un équipement."""
        Loops._stop_watcher(device.eqLogicId)  # stoppe l'éventuel thread précédent
        stop_event = threading.Event()
        myConfig.watcherStopEvents[device.eqLogicId] = stop_event
        threading.Thread(
            target=Loops.statusWatcher,
            args=(device, stop_event),
            daemon=True,
            name=f'watcher-{device.eqLogicId}'
        ).start()

    @staticmethod
    def _stop_watcher(eqLogicId: str) -> None:
        """Arrête le thread StatusWatcher d'un équipement."""
        ev = myConfig.watcherStopEvents.pop(eqLogicId, None)
        if ev:
            ev.set()
            myConfig.deviceLastStatus.pop(eqLogicId, None)

    @staticmethod
    def _stop_all_watchers() -> None:
        """Arrête tous les threads StatusWatcher."""
        for eid in list(myConfig.watcherStopEvents.keys()):
            Loops._stop_watcher(eid)

    # *** Polling d'un équipement ***
    @staticmethod
    def _poll_device(device: NutDevice) -> None:
        logging.debug('[DAEMON][%s] Interrogation NUT %s:%d', device.name, device.host, device.port)
        results = query_device(device)
        if results is not None:
            Comm.sendToJeedom.add_changes(f'update::{device.eqLogicId}', results)
            logging.info('[DAEMON][%s] Envoi de %d valeur(s) vers Jeedom', device.name, len(results))
        else:
            logging.warning('[DAEMON][%s] Aucune donnée retournée (erreur connexion)', device.name)


# ---------------------------------------------------------------------------
# Bootstrap du daemon — instanciation, gestionnaires de signaux, parsing des
# arguments CLI et bloc d'exécution principal
# ---------------------------------------------------------------------------

myConfig = Config()


def handler(signum=None, frame=None):
    logging.info('[DAEMON] Signal %d reçu, arrêt en cours...', signum)
    shutdown()


def shutdown():
    logging.info('[DAEMON] Shutdown :: Début arrêt...')
    myConfig.IS_ENDING = True
    Loops._stop_all_watchers()
    try:
        if my_jeedom_socket is not None:
            my_jeedom_socket.close()
            logging.info('[DAEMON] Shutdown :: Socket fermé')
    except Exception as e:
        logging.error('[DAEMON] Shutdown :: Erreur fermeture socket :: %s', e)
    logging.debug('[DAEMON] Shutdown :: Suppression PID :: %s', myConfig.pidFile)
    try:
        if myConfig.pidFile:
            Path(myConfig.pidFile).unlink()
            logging.debug('[DAEMON] Shutdown :: PID supprimé')
    except FileNotFoundError:
        logging.debug('[DAEMON] Shutdown :: PID déjà absent')
    except Exception as e:
        logging.error('[DAEMON] Shutdown :: Erreur suppression PID :: %s', e)
    logging.info('[DAEMON] Shutdown :: Arrêt complet')
    sys.exit(0)


parser = argparse.ArgumentParser(description='Démon NUT Free - Connexion TCP directe vers serveurs NUT')
parser.add_argument('--socketport',    help="Port d'écoute socket TCP",                             type=str)
parser.add_argument('--callback',      help='URL callback Jeedom (jeeNut_free.php)',                type=str)
parser.add_argument('--apikey',        help='Clé API Jeedom',                                       type=str)
parser.add_argument('--cyclepolling',  help='Intervalle de polling en secondes',                    type=str)
parser.add_argument('--cyclewatcher',  help='Intervalle du status watcher en secondes (défaut: 5)', type=str)
parser.add_argument('--cyclefactor',   help='Facteur multiplicateur des cycles internes',           type=str)
parser.add_argument('--loglevel',      help='Niveau de log (debug/info/warning/error)',             type=str)
parser.add_argument('--pluginversion', help='Version du plugin',                                    type=str)
parser.add_argument('--pid',           help='Chemin du fichier PID',                                type=str)

args = parser.parse_args()

if args.socketport:
    myConfig.socketPort = int(args.socketport)
if args.callback:
    myConfig.callBack = args.callback
if args.apikey:
    myConfig.apiKey = args.apikey
if args.cyclepolling:
    myConfig.cyclePolling = float(args.cyclepolling)
if args.cyclewatcher:
    myConfig.cycleWatcher = float(args.cyclewatcher)
if args.cyclefactor:
    myConfig.cycleFactor = float(args.cyclefactor)
if args.loglevel:
    myConfig.logLevel = args.loglevel
if args.pluginversion:
    myConfig.pluginVersion = args.pluginversion
if args.pid:
    myConfig.pidFile = args.pid

jeedom_utils.set_log_level(myConfig.logLevel)

# Application du cycleFactor sur les cycles internes
if myConfig.cycleFactor == 0:
    myConfig.cycleMain = 2.0
    myConfig.cycleComm = 1.0
    myConfig.cycleEvent = 1.0
    logging.warning('[DAEMON] CycleFactor=0 => cycles internes réinitialisés aux valeurs par défaut')
elif myConfig.cycleFactor < 0.5:
    myConfig.cycleMain = max(0.1, myConfig.cycleMain * myConfig.cycleFactor)
    myConfig.cycleComm = max(0.1, myConfig.cycleComm * myConfig.cycleFactor)
    myConfig.cycleEvent = max(0.1, myConfig.cycleEvent * myConfig.cycleFactor)
    logging.warning('[DAEMON] CycleFactor < 0.5 => cycles internes réduits')
else:
    myConfig.cycleMain = myConfig.cycleMain * myConfig.cycleFactor
    myConfig.cycleComm = myConfig.cycleComm * myConfig.cycleFactor
    myConfig.cycleEvent = myConfig.cycleEvent * myConfig.cycleFactor

logging.info('[DAEMON] ==========================================')
logging.info('[DAEMON] Démarrage Démon NUT Free')
logging.info('[DAEMON] Plugin Version : %s', myConfig.pluginVersion)
logging.info('[DAEMON] Python Version : %s', sys.version)
logging.info('[DAEMON] Log level      : %s', myConfig.logLevel)
logging.info('[DAEMON] Socket port    : %d', myConfig.socketPort)
logging.info('[DAEMON] CyclePolling   : %ss', myConfig.cyclePolling)
logging.info('[DAEMON] CycleWatcher   : %ss (alert: %ss)', myConfig.cycleWatcher, myConfig.cycleWatcherAlert)
logging.info('[DAEMON] CycleFactor    : %s', myConfig.cycleFactor)
logging.info('[DAEMON] CycleMain      : %s', myConfig.cycleMain)
logging.info('[DAEMON] CycleComm      : %s', myConfig.cycleComm)
logging.info('[DAEMON] CycleEvent     : %s', myConfig.cycleEvent)
logging.info('[DAEMON] Callback       : %s', myConfig.callBack)
logging.info('[DAEMON] PID file       : %s', myConfig.pidFile)

signal.signal(signal.SIGTERM, handler)
signal.signal(signal.SIGINT, handler)

my_jeedom_socket: Any = None

try:
    if myConfig.pidFile:
        jeedom_utils.write_pid(myConfig.pidFile)
        logging.info('[DAEMON] PID écrit dans %s', myConfig.pidFile)

    Comm.sendToJeedom = jeedom_com(apikey=myConfig.apiKey, url=myConfig.callBack, cycle=myConfig.cycleComm)

    my_jeedom_socket = jeedom_socket(address=myConfig.socketHost, port=myConfig.socketPort)

    Loops.mainLoop(myConfig.cycleMain)

except Exception as e:
    logging.error('[DAEMON] Erreur fatale :: %s', e)
    shutdown()
