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
from pathlib import Path
from typing import TYPE_CHECKING, Any, ClassVar, Final

if TYPE_CHECKING:
    from nutfreed import NutDevice


class Config:
    # --- Constantes immuables au niveau classe ---
    heartbeatFrequency: Final[int] = 600  # intervalle heartbeat en secondes
    NUT_VARS_PATH: ClassVar[Path] = (Path(__file__).parent.parent / 'nut_vars.json').resolve()
    nutVarsCache: ClassVar[dict[str, Any] | None] = None
    nutVarsLock: ClassVar[threading.Lock] = threading.Lock()

    def __init__(self) -> None:
        # --- État d'exécution ---
        self.IS_ENDING: bool = False
        self.heartbeatLastTime: int = int(time.time())

        # --- Configuration (valorisée par les args CLI) ---
        self.pluginVersion: str = '0.0.0'
        self.logLevel: str = 'error'
        self.socketPort: int = 55113
        self.socketHost: str = '127.0.0.1'
        self.pidFile: str = ''
        self.apiKey: str = ''
        self.callBack: str = ''

        self.cyclePolling: float = 60.0
        self.cycleMain: float = 2.0    # cycle de la boucle principale (tick détection IS_ENDING)
        self.cycleEvent: float = 1.0   # cycle de la boucle events from Jeedom
        self.cycleComm: float = 1.0    # cycle de la boucle comm vers Jeedom
        self.cycleFactor: float = 1.0  # facteur multiplicateur appliqué aux cycles internes
        self.cycleWatcher: float = 5.0       # cycle du status watcher par équipement (normal)
        self.cycleWatcherAlert: float = 2.0  # cycle réduit quand UPS sur batterie (OB)

        # --- Équipements surveillés ---
        self.devices: dict[str, 'NutDevice'] = {}                # dict[eqLogicId, NutDevice]
        self.devicesLock: threading.Lock = threading.Lock()
        self.watcherStopEvents: dict[str, threading.Event] = {}  # dict[eqLogicId, Event]
        self.deviceLastStatus: dict[str, str] = {}               # dict[eqLogicId, ups.status]


class Comm:
    sendToJeedom: ClassVar[Any] = None
