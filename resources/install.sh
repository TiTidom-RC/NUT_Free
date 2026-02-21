#!/bin/bash
PROGRESS_FILE=/tmp/jeedom/Nut_free/dependency
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi

function log(){
	if [ -n "$1" ]; then
		echo "$(date +'[%F %T]') $1";
	else
		while IFS= read -r IN; do
			echo "$(date +'[%F %T]') $IN";
		done
	fi
}

BASE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REQUIREMENTS_FILE=${BASE_DIR}/requirements.txt
VENV_DIR=${BASE_DIR}/venv
CLI_PHP=${BASE_DIR}/../core/php/Nut_freecli.php

FORCE_INIT_VENV=0
if [ ! -z $2 ]; then
	FORCE_INIT_VENV=$2
fi

cd ${BASE_DIR}

touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
log "**********************"
log "* Check Script Params *"
log "**********************"
if [ "$FORCE_INIT_VENV" -eq 1 ]; then
	log "** Force Reinit Venv :: YES **"
else
	log "** Force Reinit Venv :: NO **"
fi
echo 1 > ${PROGRESS_FILE}
log "***************************"
log "* Check Python3.x Version *"
log "***************************"
PYTHON=$(command -v python3 || true)
if [ -z "${PYTHON}" ]; then
	log "Python3 :: NOT FOUND"
	exit 1
fi
versionPython=$(python3 --version | awk -F'[ ,.]' '{print $3}')
[[ -z "$versionPython" ]] && versionPython=0
if [ "$versionPython" -eq 0 ]; then
	log "Python3.x :: VERSION ERROR :: NOT FOUND"
	exit 1
else
	log "Python3.x Version :: 3.${versionPython} (${PYTHON})"
fi
log "** Check Python3 Version :: Done **"
echo 10 > ${PROGRESS_FILE}
log "**************************"
log "* Create Python3 venv    *"
log "**************************"
if [ "$FORCE_INIT_VENV" -eq 1 ]; then
	python3 -m venv --clear --upgrade-deps ${VENV_DIR} | log
else
	python3 -m venv --upgrade-deps ${VENV_DIR} | log
fi
log "** Create Python3 Venv :: Done **"
echo 55 > ${PROGRESS_FILE}
log "*****************************"
log "* Install Python3 libraries *"
log "*****************************"
${VENV_DIR}/bin/python3 -m pip install --upgrade pip wheel | log
log "** Install Pip / Wheel :: Done **"
echo 70 > ${PROGRESS_FILE}
${VENV_DIR}/bin/python3 -m pip install -r ${REQUIREMENTS_FILE} | log
log "** Install Python3 libraries :: Done **"
echo 85 > ${PROGRESS_FILE}
log "********************"
log "* SSH-Manager (opt) *"
log "********************"
if [ -f "${CLI_PHP}" ]; then
	log "** Vérification/installation du plugin SSH-Manager... **"
	php "${CLI_PHP}" depinstall "Nut_free_update" 2>&1 | log || true
	log "** SSH-Manager :: Done **"
else
	log "** CLI PHP introuvable, étape SSH-Manager ignorée **"
fi
echo 95 > ${PROGRESS_FILE}
log "****************************"
log "* Set Owner on Directories *"
log "****************************"
if [ -d ${VENV_DIR} ]; then
	chown -Rh www-data:www-data ${VENV_DIR} | log
	log "** Set Owner for Venv Dir :: Done **"
fi
echo 100 > ${PROGRESS_FILE}
log "****************"
log "* Install DONE *"
log "****************"
rm ${PROGRESS_FILE}

