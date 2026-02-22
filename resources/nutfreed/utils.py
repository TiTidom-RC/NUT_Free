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

import time
import threading
from typing import Any


class Config:
    IS_ENDING: bool = False

    pluginVersion = '0.0.0'
    logLevel = 'error'
    socketPort = 55113
    socketHost = '127.0.0.1'
    pidFile = ''
    apiKey = ''
    callBack = ''

    cyclePolling = 60.0
    cycleMain = 0.5  # cycle de la boucle principale (tick détection IS_ENDING)
    cycleEvent = 0.5  # cycle de la boucle events from Jeedom
    cycleComm = 0.5  # cycle de la boucle comm vers Jeedom
    cycleFactor = 1.0  # facteur multiplicateur appliqué aux cycles internes
    cycleWatcher = 5.0    # cycle du status watcher par équipement (normal)
    cycleWatcherAlert = 2.0  # cycle réduit quand UPS sur batterie (OB)

    HeartbeatFrequency = 600  # intervalle heartbeat en secondes
    HeartbeatLastTime = int(time.time())

    devices: dict = {}  # dict[eqLogic_id, NutDevice]
    devicesLock = threading.Lock()

    watcherStopEvents: dict = {}   # dict[eqLogic_id, threading.Event]
    deviceLastStatus: dict = {}    # dict[eqLogic_id, str] — dernier ups.status connu


class Comm:
    sendToJeedom: Any = None
