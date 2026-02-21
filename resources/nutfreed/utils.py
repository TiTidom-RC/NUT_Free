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

import threading


class Config:
    IS_ENDING = False

    logLevel = 'error'
    socketPort = 55113
    socketHost = '127.0.0.1'
    pidFile = ''
    apiKey = ''
    callBack = ''
    cycle = 60.0
    cycleEvent = 0.5  # cycle de la boucle events from Jeedom
    cycleComm = 0.5   # cycle de la boucle comm vers Jeedom

    devices: dict = {}       # dict[eqLogic_id, NutDevice]
    devicesLock = threading.Lock()


class Comm:
    sendToJeedom = None
