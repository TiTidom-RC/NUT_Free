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
NUT_Free daemon — nutfreed.py
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
import time
import signal
import json
import argparse
import threading
from dataclasses import dataclass, field
from typing import Optional
from queue import Queue, Empty

# --- Import pynutclient ---
try:
    from pynutclient import PyNUTClient
except ImportError as e:
    print(f'[DAEMON][IMPORT] ERREUR : pynutclient introuvable :: {e}')
    print('[DAEMON][IMPORT] Installez le venv : resources/install.sh')
    sys.exit(1)

# --- Import Jeedom lib ---
try:
    from jeedom.jeedom import jeedom_utils, jeedom_com, jeedom_socket, JEEDOM_SOCKET_MESSAGE
except ImportError as e:
    print(f'[DAEMON][IMPORT] ERREUR : jeedom lib introuvable :: {e}')
    sys.exit(1)

# ---------------------------------------------------------------------------
# Mapping variables NUT → logicalId Jeedom (miroir de $_infosMap PHP)
# Les entrées sans 'nut_var' (ssh_op, cnx_ssh) sont ignorées car gérées en PHP
# ---------------------------------------------------------------------------
NUT_VARS: list[dict] = [
    {'logicalId': 'Marque',             'nut_var': 'device.mfr'},
    {'logicalId': 'Model',              'nut_var': 'device.model'},
    {'logicalId': 'ups_serial',         'nut_var': 'ups.serial'},
    {'logicalId': 'ups_line',           'nut_var': 'ups.status'},
    {'logicalId': 'input_volt',         'nut_var': 'input.voltage'},
    {'logicalId': 'input_freq',         'nut_var': 'input.frequency'},
    {'logicalId': 'output_volt',        'nut_var': 'output.voltage'},
    {'logicalId': 'output_freq',        'nut_var': 'output.frequency'},
    {'logicalId': 'output_power',       'nut_var': 'ups.power'},
    {'logicalId': 'output_real_power',  'nut_var': 'ups.realpower'},
    {'logicalId': 'batt_charge',        'nut_var': 'battery.charge'},
    {'logicalId': 'batt_volt',          'nut_var': 'battery.voltage'},
    {'logicalId': 'batt_temp',          'nut_var': 'battery.temperature'},
    {'logicalId': 'ups_temp',           'nut_var': 'ups.temperature'},
    {'logicalId': 'ups_load',           'nut_var': 'ups.load'},
    {'logicalId': 'batt_runtime',       'nut_var': 'battery.runtime'},
    {'logicalId': 'batt_runtime_min',   'nut_var': 'battery.runtime'},      # converti en minutes
    {'logicalId': 'timer_shutdown',     'nut_var': 'ups.timer.shutdown'},
    {'logicalId': 'timer_shutdown_min', 'nut_var': 'ups.timer.shutdown'},   # converti en minutes
    {'logicalId': 'beeper_stat',        'nut_var': 'ups.beeper.status'},
]

# ---------------------------------------------------------------------------

@dataclass
class NutDevice:
    eqLogic_id: str
    host: str
    port: int
    ups_name: str       # vide = auto-détection via GetUPSList()
    auto_detect: bool

    @classmethod
    def from_dict(cls, d: dict) -> 'NutDevice':
        return cls(
            eqLogic_id  = str(d['eqLogic_id']),
            host        = str(d.get('host', '127.0.0.1')),
            port        = int(d.get('port', 3493)),
            ups_name    = str(d.get('ups_name', '')).strip(),
            auto_detect = bool(int(d.get('auto_detect', '1'))),
        )

# ---------------------------------------------------------------------------

def query_device(device: NutDevice) -> Optional[dict]:
    """
    Interroge un serveur NUT en TCP direct et retourne
    {logicalId: valeur} ou None en cas d'erreur de connexion.
    """
    try:
        client = PyNUTClient(host=device.host, port=device.port)
    except Exception as e:
        logging.error('[DAEMON][%s] Connexion NUT %s:%d impossible :: %s',
                      device.eqLogic_id, device.host, device.port, e)
        return None

    # Résolution du nom UPS
    ups_name = device.ups_name
    if device.auto_detect or not ups_name:
        try:
            ups_list = client.GetUPSList()
            if not ups_list:
                logging.warning('[DAEMON][%s] Aucun UPS trouvé sur %s:%d',
                                device.eqLogic_id, device.host, device.port)
                return {}
            ups_name = list(ups_list.keys())[0]
            logging.debug('[DAEMON][%s] UPS auto-détecté : %s', device.eqLogic_id, ups_name)
        except Exception as e:
            logging.error('[DAEMON][%s] GetUPSList erreur :: %s', device.eqLogic_id, e)
            return None

    # Lecture de toutes les variables NUT en une seule requête
    try:
        all_vars = client.GetUPSVars(ups_name)
    except Exception as e:
        logging.error('[DAEMON][%s] GetUPSVars(%s) erreur :: %s', device.eqLogic_id, ups_name, e)
        return None

    results: dict = {}
    not_online = False
    marque = ''

    for i, var in enumerate(NUT_VARS):
        logical_id = var['logicalId']
        nut_var    = var['nut_var']
        raw        = all_vars.get(nut_var)

        if raw is None:
            logging.debug('[DAEMON][%s] %s (%s) : non supporté', device.eqLogic_id, logical_id, nut_var)
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
            logging.debug('[DAEMON][%s] ups_line=%s not_online=%s', device.eqLogic_id, value, not_online)

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
        logging.debug('[DAEMON][%s] %s = %s', device.eqLogic_id, logical_id, value)

    return results

# ---------------------------------------------------------------------------

class NutFreeDaemon:

    def __init__(self, args: argparse.Namespace, jcom: jeedom_com):
        self._args    = args
        self._jcom    = jcom
        self._devices: dict[str, NutDevice] = {}
        self._lock    = threading.Lock()
        self._running = True

    # ---- Gestion de la liste des équipements --------------------------------

    def _set_devices(self, devices_list: list) -> None:
        with self._lock:
            self._devices = {
                NutDevice.from_dict(d).eqLogic_id: NutDevice.from_dict(d)
                for d in devices_list
            }
        logging.info('[DAEMON] Liste mise à jour : %d équipement(s)', len(self._devices))

    def _add_device(self, data: dict) -> None:
        device = NutDevice.from_dict(data)
        with self._lock:
            self._devices[device.eqLogic_id] = device
        logging.info('[DAEMON] Équipement ajouté/mis à jour : eqLogic_id=%s host=%s:%d',
                     device.eqLogic_id, device.host, device.port)

    def _remove_device(self, eqLogic_id: str) -> None:
        with self._lock:
            self._devices.pop(eqLogic_id, None)
        logging.info('[DAEMON] Équipement retiré : %s', eqLogic_id)

    # ---- Polling ------------------------------------------------------------

    def _poll_device(self, device: NutDevice) -> None:
        logging.debug('[DAEMON][%s] Interrogation NUT %s:%d', device.eqLogic_id, device.host, device.port)
        results = query_device(device)
        if results:
            self._jcom.add_changes(f'update::{device.eqLogic_id}', results)
            logging.info('[DAEMON][%s] Envoi de %d valeur(s) vers Jeedom', device.eqLogic_id, len(results))
        elif results is None:
            logging.warning('[DAEMON][%s] Aucune donnée retournée (erreur connexion)', device.eqLogic_id)

    def poll_all(self) -> None:
        with self._lock:
            devices = dict(self._devices)
        for device in devices.values():
            self._poll_device(device)

    # ---- Traitement des messages socket -------------------------------------

    def handle_message(self, raw: bytes) -> None:
        try:
            message = json.loads(raw.strip())
        except (json.JSONDecodeError, Exception) as e:
            logging.error('[DAEMON][SOCKET] Erreur décodage JSON :: %s', e)
            return

        # Validation API key
        if message.get('apikey') != self._args.apikey:
            logging.warning('[DAEMON][SOCKET] API key invalide, message ignoré')
            return

        action = message.get('action', '')
        logging.info('[DAEMON][SOCKET] Action reçue : %s', action)

        if action == 'update_devices':
            self._set_devices(message.get('devices', []))

        elif action == 'add_device':
            device_data = message.get('device')
            if device_data:
                self._add_device(device_data)

        elif action == 'remove_device':
            self._remove_device(str(message.get('eqLogic_id', '')))

        elif action == 'query_now':
            eqLogic_id = str(message.get('eqLogic_id', ''))
            with self._lock:
                device = self._devices.get(eqLogic_id)
            if device:
                self._poll_device(device)
            else:
                logging.warning('[DAEMON][SOCKET] query_now : équipement %s inconnu', eqLogic_id)

        elif action == 'shutdown':
            logging.info('[DAEMON] Arrêt demandé via socket')
            self._running = False

        else:
            logging.warning('[DAEMON][SOCKET] Action inconnue : %s', action)

    # ---- Boucle principale --------------------------------------------------

    def run(self) -> None:
        cycle_s   = float(self._args.cycle)
        last_poll = 0.0

        logging.info('[DAEMON] Démarrage de la boucle principale (cycle=%ss)', cycle_s)

        while self._running:
            # Traiter les messages socket en attente
            while True:
                try:
                    raw = JEEDOM_SOCKET_MESSAGE.get_nowait()
                    self.handle_message(raw)
                except Empty:
                    break

            # Lancer un cycle de polling si nécessaire
            now = time.time()
            if now - last_poll >= cycle_s:
                last_poll = now
                if self._devices:
                    self.poll_all()
                else:
                    logging.debug('[DAEMON] Aucun équipement enregistré, polling ignoré')

            time.sleep(0.1)

        logging.info('[DAEMON] Boucle principale terminée')

# ---------------------------------------------------------------------------

def _shutdown_handler(signum: int, _frame) -> None:
    logging.info('[DAEMON] Signal %d reçu, arrêt en cours...', signum)
    sys.exit(0)

def main() -> None:
    parser = argparse.ArgumentParser(description='NUT_Free daemon - Connexion TCP directe vers serveurs NUT')
    parser.add_argument('--socketport', type=int,   default=55200,   help='Port d\'écoute socket TCP (défaut: 55200)')
    parser.add_argument('--callback',   type=str,   required=True,   help='URL callback Jeedom (jeeNut_free.php)')
    parser.add_argument('--apikey',     type=str,   required=True,   help='Clé API Jeedom')
    parser.add_argument('--cycle',      type=float, default=60.0,    help='Intervalle de polling en secondes (défaut: 60)')
    parser.add_argument('--loglevel',   type=str,   default='error', help='Niveau de log (debug/info/warning/error)')
    parser.add_argument('--pid',        type=str,   default='',      help='Chemin du fichier PID')
    args = parser.parse_args()

    # Configuration du logging
    jeedom_utils.set_log_level(args.loglevel)
    logging.info('[DAEMON] ==========================================')
    logging.info('[DAEMON] Démarrage NUT_Free daemon')
    logging.info('[DAEMON] socketport=%d | cycle=%ss | loglevel=%s', args.socketport, args.cycle, args.loglevel)

    # Fichier PID
    if args.pid:
        os.makedirs(os.path.dirname(os.path.abspath(args.pid)), exist_ok=True)
        jeedom_utils.write_pid(args.pid)
        logging.info('[DAEMON] PID écrit dans %s', args.pid)

    # Signaux
    signal.signal(signal.SIGTERM, _shutdown_handler)
    signal.signal(signal.SIGINT,  _shutdown_handler)

    # Callback Jeedom (envoi async des données)
    jcom = jeedom_com(
        apikey = args.apikey,
        url    = args.callback,
        cycle  = 1.0,
    )

    # Socket TCP pour recevoir les commandes PHP
    sock = jeedom_socket(address='127.0.0.1', port=args.socketport)
    sock.open()
    logging.info('[DAEMON] Socket d\'écoute démarré sur 127.0.0.1:%d', args.socketport)

    # Daemon
    daemon = NutFreeDaemon(args=args, jcom=jcom)
    try:
        daemon.run()
    except KeyboardInterrupt:
        logging.info('[DAEMON] Interruption clavier')
    finally:
        logging.info('[DAEMON] Fermeture socket')
        sock.close()
        if args.pid and os.path.exists(args.pid):
            os.remove(args.pid)
        logging.info('[DAEMON] NUT_Free daemon arrêté')


if __name__ == '__main__':
    main()
