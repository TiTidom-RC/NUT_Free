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
import os
import socket
import time
import signal
import json
import argparse
import threading
from dataclasses import dataclass
from typing import Any, Optional

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
# Mapping variables NUT → logicalId Jeedom (miroir de $_infosMap PHP)
# Les entrées sans 'nut_var' (ssh_op, cnx_ssh) sont ignorées car gérées en PHP
# ---------------------------------------------------------------------------
NUT_VARS: list[dict] = [
    {'logicalId': 'Marque', 'nut_var': 'device.mfr'},
    {'logicalId': 'Model', 'nut_var': 'device.model'},
    {'logicalId': 'ups_serial', 'nut_var': 'ups.serial'},
    {'logicalId': 'ups_line', 'nut_var': 'ups.status'},
    {'logicalId': 'input_volt', 'nut_var': 'input.voltage'},
    {'logicalId': 'input_freq', 'nut_var': 'input.frequency'},
    {'logicalId': 'output_volt', 'nut_var': 'output.voltage'},
    {'logicalId': 'output_freq', 'nut_var': 'output.frequency'},
    {'logicalId': 'output_power', 'nut_var': 'ups.power'},
    {'logicalId': 'output_real_power', 'nut_var': 'ups.realpower'},
    {'logicalId': 'batt_charge', 'nut_var': 'battery.charge'},
    {'logicalId': 'batt_volt', 'nut_var': 'battery.voltage'},
    {'logicalId': 'batt_temp', 'nut_var': 'battery.temperature'},
    {'logicalId': 'ups_temp', 'nut_var': 'ups.temperature'},
    {'logicalId': 'ups_load', 'nut_var': 'ups.load'},
    {'logicalId': 'batt_runtime', 'nut_var': 'battery.runtime'},
    {'logicalId': 'batt_runtime_min', 'nut_var': 'battery.runtime'},  # converti en minutes
    {'logicalId': 'timer_shutdown', 'nut_var': 'ups.timer.shutdown'},
    {'logicalId': 'timer_shutdown_min', 'nut_var': 'ups.timer.shutdown'},  # converti en minutes
    {'logicalId': 'beeper_stat', 'nut_var': 'ups.beeper.status'},
]


# ---------------------------------------------------------------------------

@dataclass
class NutDevice:
    eqLogicId: str
    host: str
    port: int
    upsName: str       # vide = auto-détection via GetUPSList()
    autoDetect: bool
    nutLogin: Optional[str] = None    # login upsd (None = pas d'authentification)
    nutPassword: Optional[str] = None  # mot de passe upsd (None = pas d'authentification)
    resolvedUpsName: Optional[str] = None  # nom résolu et mis en cache après la première détection

    @classmethod
    def from_dict(cls, d: dict) -> 'NutDevice':
        login = str(d.get('nutLogin', '')).strip() or None
        password = str(d.get('nutPassword', '')).strip() or None
        return cls(
            eqLogicId=str(d['eqLogicId']),
            host=str(d.get('host', '127.0.0.1')),
            port=int(d.get('port', 3493)),
            upsName=str(d.get('upsName', '')).strip(),
            autoDetect=bool(int(d.get('autoDetect', '1'))),
            nutLogin=login,
            nutPassword=password,
            resolvedUpsName=None,
        )


# ---------------------------------------------------------------------------

def _resolve_ups_name(device: NutDevice) -> Optional[str]:
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
        ups_list = client.GetUPSList()
        if not ups_list:
            logging.warning('[DAEMON][%s] Aucun UPS trouvé sur %s:%d',
                            device.eqLogicId, device.host, device.port)
            return None
        name = list(ups_list.keys())[0].decode('ascii') if isinstance(list(ups_list.keys())[0], bytes) else list(ups_list.keys())[0]
        device.resolvedUpsName = name
        logging.debug('[DAEMON][%s] UPS auto-détecté et mis en cache : %s', device.eqLogicId, name)
        return device.resolvedUpsName
    except Exception as e:
        logging.error('[DAEMON][%s] _resolve_ups_name erreur :: %s', device.eqLogicId, e)
        return None


def _nut_get_var(host: str, port: int, ups_name: str, var_name: str,
                 login: Optional[str] = None, password: Optional[str] = None,
                 timeout: float = 5.0) -> Optional[str]:
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
                sock.recv(256)  # attend OK
            if password:
                sock.sendall(f'PASSWORD {password}\n'.encode('ascii'))
                sock.recv(256)  # attend OK
            sock.sendall(f'GET VAR {ups_name} {var_name}\n'.encode('ascii'))
            buf = b''
            while b'\n' not in buf:
                chunk = sock.recv(256)
                if not chunk:
                    break
                buf += chunk
        line = buf.split(b'\n')[0].decode('ascii').strip()
        # Réponse attendue : VAR <ups> <var> "<value>"
        if line.startswith('VAR '):
            parts = line.split('"')
            return parts[1] if len(parts) >= 2 else None
        logging.debug('[WATCHER] _nut_get_var réponse inattendue : %s', line)
        return None
    except Exception as e:
        logging.debug('[WATCHER] _nut_get_var %s:%d %s=%s :: %s', host, port, ups_name, var_name, e)
        return None


def get_ups_status(device: NutDevice) -> Optional[str]:
    """
    Lecture légère de ups.status uniquement via GET VAR (protocole brut).
    Utilisé par le StatusWatcher pour détecter les changements d'état.
    Retourne la valeur brute (ex: 'OL', 'OB LB') ou None en cas d'erreur.
    """
    ups_name = _resolve_ups_name(device)
    if not ups_name:
        return None
    return _nut_get_var(device.host, device.port, ups_name, 'ups.status',
                        login=device.nutLogin, password=device.nutPassword)


def query_device(device: NutDevice) -> Optional[dict]:
    """
    Interroge un serveur NUT en TCP direct et retourne
    {logicalId: valeur} ou None en cas d'erreur de connexion.
    """
    # Résolution du nom UPS (depuis le cache ou via GetUPSList)
    ups_name = _resolve_ups_name(device)
    if not ups_name:
        logging.warning('[DAEMON][%s] Nom UPS non résolu, poll ignoré', device.eqLogicId)
        return None

    # Connexion pour le poll complet (LIST VAR — toutes les variables)
    try:
        client = PyNUTClient(host=device.host, port=device.port,
                             login=device.nutLogin, password=device.nutPassword)
    except Exception as e:
        logging.error('[DAEMON][%s] Connexion NUT %s:%d impossible :: %s',
                      device.eqLogicId, device.host, device.port, e)
        return None

    # Lecture de toutes les variables NUT en une seule requête
    try:
        all_vars = client.GetUPSVars(ups_name)
    except Exception as e:
        logging.error('[DAEMON][%s] GetUPSVars(%s) erreur :: %s', device.eqLogicId, ups_name, e)
        return None

    results: dict = {}
    not_online = False
    marque = ''

    for var in NUT_VARS:
        logical_id = var['logicalId']
        nut_var = var['nut_var']
        raw = all_vars.get(nut_var)

        if raw is None:
            logging.debug('[DAEMON][%s] %s (%s) : non supporté', device.eqLogicId, logical_id, nut_var)
            continue

        value = str(raw).strip()

        # Concaténation Marque + Modèle sur une ligne
        if logical_id == 'Marque':
            marque = value
        elif logical_id == 'Model':
            value = f'{marque} {value}'.strip()

        # Détection mode batterie
        if logical_id == 'ups_line':
            not_online = 'OL' not in value.upper()
            logging.debug('[DAEMON][%s] ups_line=%s not_online=%s', device.eqLogicId, value, not_online)

        # Tension entrée forcée à 0 si sur batterie
        if logical_id == 'input_volt' and not_online:
            value = '0'

        # Conversion secondes → minutes
        if logical_id in ('batt_runtime_min', 'timer_shutdown_min'):
            try:
                value = str(int(float(value) / 60))
            except (ValueError, TypeError):
                pass

        results[logical_id] = value
        logging.debug('[DAEMON][%s] %s = %s', device.eqLogicId, logical_id, value)

    return results


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
                            myConfig.devices = {
                                NutDevice.from_dict(d).eqLogicId: NutDevice.from_dict(d)
                                for d in message.get('devices', [])
                            }
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
                            logging.info('[DAEMON] Équipement ajouté/mis à jour : eqLogicId=%s host=%s:%d',
                                         device.eqLogicId, device.host, device.port)

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
                        Loops._poll_device(device)
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
                     device.eqLogicId, myConfig.cycleWatcher, myConfig.cycleWatcherAlert)
        current_cycle = myConfig.cycleWatcher
        first_poll = True

        while not stop_event.is_set() and not myConfig.IS_ENDING:
            status = get_ups_status(device)

            if status is not None:
                last = myConfig.deviceLastStatus.get(device.eqLogicId, '')
                if status != last:
                    if first_poll:
                        logging.info('[WATCHER][%s] Statut initial : %s', device.eqLogicId, status)
                    else:
                        logging.info('[WATCHER][%s] Changement statut : \'%s\' → \'%s\'',
                                     device.eqLogicId, last, status)
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

        logging.info('[WATCHER][%s] Arrêt surveillance statut', device.eqLogicId)

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
        logging.debug('[DAEMON][%s] Interrogation NUT %s:%d', device.eqLogicId, device.host, device.port)
        results = query_device(device)
        if results:
            Comm.sendToJeedom.add_changes(f'update::{device.eqLogicId}', results)
            logging.info('[DAEMON][%s] Envoi de %d valeur(s) vers Jeedom', device.eqLogicId, len(results))
        elif results is None:
            logging.warning('[DAEMON][%s] Aucune donnée retournée (erreur connexion)', device.eqLogicId)


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
            os.remove(myConfig.pidFile)
            logging.debug('[DAEMON] Shutdown :: PID supprimé')
    except FileNotFoundError:
        logging.debug('[DAEMON] Shutdown :: PID déjà absent')
    except Exception as e:
        logging.error('[DAEMON] Shutdown :: Erreur suppression PID :: %s', e)
    logging.info('[DAEMON] Shutdown :: Arrêt complet')
    sys.exit(0)


parser = argparse.ArgumentParser(description='Démon NUT Free - Connexion TCP directe vers serveurs NUT')
parser.add_argument('--socketport', help="Port d'écoute socket TCP", type=str)
parser.add_argument('--callback', help='URL callback Jeedom (jeeNut_free.php)', type=str)
parser.add_argument('--apikey', help='Clé API Jeedom', type=str)
parser.add_argument('--cyclepolling', help='Intervalle de polling en secondes', type=str)
parser.add_argument('--cyclewatcher', help='Intervalle du status watcher en secondes (défaut: 5)', type=str)
parser.add_argument('--cyclefactor', help='Facteur multiplicateur des cycles internes', type=str)
parser.add_argument('--loglevel', help='Niveau de log (debug/info/warning/error)', type=str)
parser.add_argument('--pluginversion', help='Version du plugin', type=str)
parser.add_argument('--pid', help='Chemin du fichier PID', type=str)

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
