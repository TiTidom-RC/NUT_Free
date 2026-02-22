<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class Nut_free extends eqLogic {

	// Chemins venv Python et daemon
	const VENV_PYTHON    = __DIR__ . '/../../resources/venv/bin/python3';
	const DAEMON_SCRIPT  = __DIR__ . '/../../resources/nutfreed/nutfreed.py';
	const DAEMON_PORT    = 55113;
	const PYENV_PATH     = '/opt/pyenv/bin/pyenv';

	public static $_widgetPossibility = array('custom' => true, 'custom::layout' => false);

	// Clés = logicalId ; chaque entrée décrit une variable NUT et la commande Jeedom associée.
	public static $_infosMap = array(
		'Marque'             => array('name' => 'Marque_Model',                    'template_dashboard' => 'line', 'subtype' => 'string', 'cmd' => 'device.mfr'),
		'Model'              => array('name' => 'Model',                                                           'subtype' => 'string', 'cmd' => 'device.model'),
		'ups_serial'         => array('name' => 'Serial',                                                         'subtype' => 'string', 'cmd' => 'ups.serial'),
		'ups_line'           => array('name' => 'UPS MODE',                                                       'subtype' => 'string', 'cmd' => 'ups.status'),
		'input_volt'         => array('name' => 'Tension en entrée',               'unite' => 'V',                                        'cmd' => 'input.voltage'),
		'input_freq'         => array('name' => 'Fréquence en entrée',             'unite' => 'Hz',                                       'cmd' => 'input.frequency'),
		'output_volt'        => array('name' => 'Tension en sortie',               'unite' => 'V',                                        'cmd' => 'output.voltage'),
		'output_freq'        => array('name' => 'Fréquence en sortie',             'unite' => 'Hz',                                       'cmd' => 'output.frequency'),
		'output_power'       => array('name' => 'Puissance en sortie',             'unite' => 'VA',                                       'cmd' => 'ups.power'),
		'output_real_power'  => array('name' => 'Puissance en sortie réel',        'unite' => 'W',                                        'cmd' => 'ups.realpower'),
		'batt_charge'        => array('name' => 'Niveau de charge batterie',       'unite' => '%',                                        'cmd' => 'battery.charge'),
		'batt_volt'          => array('name' => 'Tension de la batterie',          'unite' => 'V',                                        'cmd' => 'battery.voltage'),
		'batt_temp'          => array('name' => 'Température de la batterie',      'unite' => '°C',                                       'cmd' => 'battery.temperature'),
		'ups_temp'           => array('name' => 'Température ups',                 'unite' => '°C',                                       'cmd' => 'ups.temperature'),
		'ups_load'           => array('name' => 'Charge onduleur',                 'unite' => '%',                                        'cmd' => 'ups.load'),
		'batt_runtime'       => array('name' => 'Temps restant sur batterie en s', 'unite' => 's',                                        'cmd' => 'battery.runtime'),
		'batt_runtime_min'   => array('name' => 'Temps restant sur batterie en min','unite' => 'min',                                     'cmd' => 'battery.runtime'),
		'timer_shutdown'     => array('name' => 'Temps restant avant arrêt en s',  'unite' => 's',                                        'cmd' => 'ups.timer.shutdown'),
		'timer_shutdown_min' => array('name' => 'Temps restant avant arrêt en min','unite' => 'min',                                     'cmd' => 'ups.timer.shutdown'),
		'beeper_stat'        => array('name' => 'Beeper',                                                         'subtype' => 'string', 'cmd' => 'ups.beeper.status'),
	);

    public static function cron() {
        foreach (eqLogic::byType('Nut_free') as $Nut_free) {
            /** @var Nut_free $Nut_free */
            if (!$Nut_free->getIsEnable()) continue;
            $mode = $Nut_free->getConfiguration('connexionMode', 'nut');
            if ($mode === 'nut') {
                // Mode local : le daemon Python gère le polling automatiquement.
                // On s'assure simplement que l'équipement est enregistré dans le daemon.
                self::sendToDaemon(array(
                    'action'      => 'add_device',
                    'device'      => array(
                        'eqLogicId'   => $Nut_free->getId(),
                        'host'        => $Nut_free->getConfiguration('addressIp', '127.0.0.1'),
                        'port'        => (int) $Nut_free->getConfiguration('nutPort', 3493),
                        'upsName'     => $Nut_free->getConfiguration('ups', ''),
                        'autoDetect'  => ($Nut_free->getConfiguration('upsAutoSelect', '0') === '0') ? 1 : 0,
                        'nutLogin'    => $Nut_free->getConfiguration('nutLogin', ''),
                        'nutPassword' => $Nut_free->getConfiguration('nutPassword', ''),
                    ),
                ));
            } else {
                // Mode distant : collecte via SSH-Manager directement en PHP
                $Nut_free->getInfosSSH();
                $Nut_free->refreshWidget();
            }
        }
    }

	
	public static function getPluginBranch() {
		$pluginBranch = 'N/A';
		try {
			$_update = update::byLogicalId('Nut_free');
			$pluginBranch = $_update->getConfiguration('version', 'N/A') . ' (' . $_update->getSource() . ')';
		} catch (\Exception $e) {
			log::add('Nut_free', 'warning', '[BRANCH] Get ERROR :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[BRANCH] PluginBranch :: ' . $pluginBranch);
		return $pluginBranch;
	}

	public static function getPluginVersion() {
		$pluginVersion = '0.0.0';
		try {
			if (!file_exists(dirname(__FILE__) . '/../../plugin_info/info.json')) {
				log::add('Nut_free', 'warning', '[VERSION] fichier info.json manquant');
			}
			$data = json_decode(file_get_contents(dirname(__FILE__) . '/../../plugin_info/info.json'), true);
			if (!is_array($data)) {
				log::add('Nut_free', 'warning', '[VERSION] Impossible de décoder le fichier info.json');
			}
			try {
				$pluginVersion = $data['pluginVersion'];
			} catch (\Exception $e) {
				log::add('Nut_free', 'warning', '[VERSION] Impossible de récupérer la version du plugin');
			}
		} catch (\Exception $e) {
			log::add('Nut_free', 'warning', '[VERSION] Get ERROR :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[VERSION] PluginVersion :: ' . $pluginVersion);
		return $pluginVersion;
	}

	public static function dependancy_info() {
		$_logName = __CLASS__ . '_update';
		$return = array();
		$return['log'] = log::getPathToLog($_logName);
		$return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependency';

		if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependency')) {
			$return['state'] = 'in_progress';
		} else {
			if (!file_exists(self::VENV_PYTHON)) {
				log::add($_logName, 'debug', '[DepInfo][ERROR] Python venv introuvable');
				$return['state'] = 'nok';
			} elseif (exec(self::VENV_PYTHON . ' -m pip freeze | grep -Eiwc "' . config::byKey('pythonDepString', 'Nut_free', '', true) . '"') < config::byKey('pythonDepNum', 'Nut_free', 0, true)) {
				log::add($_logName, 'debug', '[DepInfo][ERROR] Missing Python dependencies');
				$return['state'] = 'nok';
			} else {
				log::add($_logName, 'debug', '[DepInfo][INFO] All dependencies are installed');
				$return['state'] = 'ok';
			}
		}
		return $return;
	}

	public static function getPythonDepFromRequirements() {
		$pythonDepString = '';
		$pythonDepNum = 0;
		try {
			if (!file_exists(dirname(__FILE__) . '/../../resources/requirements.txt')) {
				log::add('Nut_free', 'error', '[Python-Dep] Fichier requirements.txt manquant');
				config::save('pythonDepString', $pythonDepString, 'Nut_free');
				config::save('pythonDepNum', $pythonDepNum, 'Nut_free');
				return false;
			}
			$data = file_get_contents(dirname(__FILE__) . '/../../resources/requirements.txt');
			if (!is_string($data)) {
				log::add('Nut_free', 'error', '[Python-Dep] Impossible de lire le fichier requirements.txt');
				config::save('pythonDepString', $pythonDepString, 'Nut_free');
				config::save('pythonDepNum', $pythonDepNum, 'Nut_free');
				return false;
			}
			$lines = explode("\n", $data);
			$nonEmptyLines = array_filter($lines, function($line) {
				return trim($line) !== '';
			});
			$pythonDepString = join('|', $nonEmptyLines);
			$pythonDepNum = count($nonEmptyLines);
		} catch (\Exception $e) {
			log::add('Nut_free', 'debug', '[Python-Dep] Get requirements.txt ERROR :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'debug', '[Python-Dep] PythonDepString / PythonDepNum :: ' . $pythonDepString . ' / ' . $pythonDepNum);
		config::save('pythonDepString', $pythonDepString, 'Nut_free');
		config::save('pythonDepNum', $pythonDepNum, 'Nut_free');
		return true;
	}

	public static function getPythonVersion() {
		$pythonVersion = '0.0.0';
		try {
			if (file_exists(self::VENV_PYTHON)) {
				$pythonVersion = exec(system::getCmdSudo() . self::VENV_PYTHON . " --version | awk '{ print $2 }'");
				config::save('pythonVersion', $pythonVersion, 'Nut_free');
			} else {
				log::add('Nut_free', 'error', '[Python-Version] Python venv introuvable :: ' . self::VENV_PYTHON);
			}
		} catch (\Exception $e) {
			log::add('Nut_free', 'error', '[Python-Version] Exception :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[Python-Version] PythonVersion (venv) :: ' . $pythonVersion);
		return $pythonVersion;
	}

	public static function getPyEnvVersion() {
		$pyenvVersion = '0.0.0';
		try {
			if (file_exists(self::PYENV_PATH)) {
				$pyenvVersion = exec(system::getCmdSudo() . self::PYENV_PATH . " --version | awk '{ print $2 }'");
				config::save('pyenvVersion', $pyenvVersion, 'Nut_free');
			} elseif (file_exists(self::VENV_PYTHON)) {
				$pythonPyEnvInUse = (exec(system::getCmdSudo() . 'dirname $(readlink ' . self::VENV_PYTHON . ') | grep -Ewc "opt/pyenv"') == 1) ? true : false;
				if (!$pythonPyEnvInUse) {
					$pyenvVersion = '-';
					config::save('pyenvVersion', $pyenvVersion, 'Nut_free');
				}
			} else {
				log::add('Nut_free', 'error', '[PyEnv-Version] PyEnv File :: KO');
			}
		} catch (\Exception $e) {
			log::add('Nut_free', 'error', '[PyEnv-Version] Exception :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[PyEnv-Version] PyEnvVersion :: ' . $pyenvVersion);
		return $pyenvVersion;
	}

	public static function dependancy_install() {
		log::remove(__CLASS__ . '_update');

		$script_sysUpdates  = 0;
		$script_restorePyEnv = 0;
		$script_restoreVenv = 0;

		if (config::byKey('debugInstallUpdates', 'Nut_free') == '1') {
			$script_sysUpdates = 1;
			config::save('debugInstallUpdates', '0', 'Nut_free');
		}
		if (config::byKey('debugRestorePyEnv', 'Nut_free') == '1') {
			$script_restorePyEnv = 1;
			config::save('debugRestorePyEnv', '0', 'Nut_free');
		}
		if (config::byKey('debugRestoreVenv', 'Nut_free') == '1') {
			$script_restoreVenv = 1;
			config::save('debugRestoreVenv', '0', 'Nut_free');
		}

		return array(
			'script' => __DIR__ . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency' . ' ' . $script_sysUpdates . ' ' . $script_restorePyEnv . ' ' . $script_restoreVenv,
			'log'    => log::getPathToLog(__CLASS__ . '_update'),
		);
	}

	public function postSave() {
		static::createCmd($this);

		// Déclencher une première collecte selon le mode de connexion
		$mode = $this->getConfiguration('connexionMode', 'nut');
		if ($mode === 'nut') {
			self::sendToDaemon(array(
				'action' => 'add_device',
				'device' => array(
					'eqLogicId'   => $this->getId(),
					'host'        => $this->getConfiguration('addressIp', '127.0.0.1'),
					'port'        => (int) $this->getConfiguration('nutPort', 3493),
					'upsName'     => $this->getConfiguration('ups', ''),
					'autoDetect'  => ($this->getConfiguration('upsAutoSelect', '0') === '0') ? 1 : 0,
					'nutLogin'    => $this->getConfiguration('nutLogin', ''),
					'nutPassword' => $this->getConfiguration('nutPassword', ''),
				),
			));
		} else {
			$this->getInfosSSH();
			$this->refreshWidget();
		}
	}

	public function postUpdate() {
		static::createCmd($this);
	}

	/**
	 * Crée ou met à jour toutes les commandes de l'équipement à partir de $_infosMap.
	 * Idempotent : peut être appelé plusieurs fois sans effet de bord.
	 */
	public static function createCmd($eqLogic) {
		$order = 0;
		foreach (self::$_infosMap as $logicalId => $info) {
			$cmd = $eqLogic->getCmd(null, $logicalId);
			if (!is_object($cmd)) {
				$cmd = new Nut_freeCmd();
				$cmd->setLogicalId($logicalId);
			}
			$cmd->setEqLogic_id($eqLogic->getId());
			$cmd->setName(__($info['name'], __FILE__));
			$cmd->setType($info['type'] ?? 'info');
			$cmd->setSubType($info['subtype'] ?? 'numeric');
			$cmd->setOrder($order);
			if (isset($info['unite'])) {
				$cmd->setUnite($info['unite']);
			}
			if (isset($info['template_dashboard'])) {
				$cmd->setTemplate('dashboard', $info['template_dashboard']);
			}
			if (isset($info['isVisible'])) {
				$cmd->setIsVisible($info['isVisible']);
			}
			try {
				$cmd->save();
			} catch (\Throwable $th) {
				log::add('Nut_free', 'error', '[createCmd] Erreur sauvegarde commande ' . $logicalId . ' : ' . $th->getMessage());
			}
			$order++;
		}
	}

	/*
	 * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
	 */
	public function decrypt() {
		$this->setConfiguration('nutLogin', utils::decrypt($this->getConfiguration('nutLogin')));
		$this->setConfiguration('nutPassword', utils::decrypt($this->getConfiguration('nutPassword')));
	}
	public function encrypt() {
		$this->setConfiguration('nutLogin', utils::encrypt($this->getConfiguration('nutLogin')));
		$this->setConfiguration('nutPassword', utils::encrypt($this->getConfiguration('nutPassword')));
	}

 	public function toHtml($_version = 'dashboard')	{
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$_version = jeedom::versionAlias($_version);
		
		foreach (self::$_infosMap as $logicalId => $info) {
			$cmd = $this->getCmd(null, $logicalId);
			$replace['#' . $logicalId . '#']          = (is_object($cmd)) ? $cmd->execCmd() : '';
			$replace['#' . $logicalId . 'id#']        = is_object($cmd) ? $cmd->getId() : '';
			$replace['#' . $logicalId . '_display#']  = (is_object($cmd) && $cmd->getIsVisible()) ? '#' . $logicalId . '_display#' : 'none';
		}
		
		$html = template_replace($replace, getTemplate('core', $_version, 'Nut_free','Nut_free'));
		
		return $html;
	}
	
    public function getInfosSSH() {
        if (!$this->getIsEnable()) return;

        $equipement      = $this->getName();
        $upsAutoSelect = $this->getConfiguration('upsAutoSelect', '0');
        $ups           = trim($this->getConfiguration('ups', ''));
        $sshHostId     = $this->getConfiguration('SSHHostId', '');

        log::add('Nut_free', 'debug', '--- [' . $equipement . '] Début collecte NUT via SSH ---');

        // --- Vérification SSH-Manager ---
        if (!class_exists('sshmanager')) {
            log::add('Nut_free', 'error', '[' . $equipement . '] Plugin SSH-Manager introuvable - vérifiez les dépendances');
            return false;
        }
        if (empty($sshHostId)) {
            log::add('Nut_free', 'error', '[' . $equipement . '] SSHHostId non configuré');
            return false;
        }

        // --- Résolution du nom de l'UPS (auto-détection si upsAutoSelect = '0') ---
        if ($upsAutoSelect === '0' || $ups === '') {
            try {
                $upsListCmd = "upsc -l 2>&1 | grep -v '^Init SSL'";
                $ups = trim((string) sshmanager::executeCmds($sshHostId, $upsListCmd));
                log::add('Nut_free', 'debug', '[' . $equipement . '] UPS auto-détecté : ' . $ups);
            } catch (\Exception $e) {
                log::add('Nut_free', 'error', '[' . $equipement . '] Auto-détection UPS échouée : ' . $e->getMessage());
                return false;
            }
        } else {
            log::add('Nut_free', 'debug', '[' . $equipement . '] UPS manuel : ' . $ups);
        }

        if (empty($ups)) {
            log::add('Nut_free', 'error', '[' . $equipement . '] Impossible de déterminer le nom de l\'UPS');
            return false;
        }

        // --- Collecte des valeurs NUT via SSH ---
        $Not_Online = 0;
        $Marque     = '';

        foreach (self::$_infosMap as $logicalId => $info) {
            if (!isset($info['cmd'])) continue;

            $result = '';
            try {
                $cmdline = 'upsc ' . escapeshellarg($ups) . ' ' . escapeshellarg($info['cmd']) . " 2>&1 | grep -v '^Init SSL'";
                $result  = trim((string) sshmanager::executeCmds($sshHostId, $cmdline));
            } catch (\Exception $e) {
                log::add('Nut_free', 'warning', '[' . $equipement . '] ' . $info['name'] . ' erreur : ' . $e->getMessage());
                continue;
            }

            $errorresult = (strpos($result, 'not supported by UPS') !== false) ? $result : '';

            // Marque + Modèle concaténés
            if ($logicalId === 'Marque') {
                $Marque = $result;
            }
            if ($logicalId === 'Model') {
                $result = trim($Marque . ' ' . $result);
            }

            // Mode ligne / batterie
            if ($logicalId === 'ups_line') {
                $Not_Online = (stripos($result, 'OL') === false) ? 1 : 0;
                log::add('Nut_free', 'debug', '[' . $equipement . '] ups_line Not_Online=' . $Not_Online . ' result=' . $result);
            }

            // Tension entrée forcée à 0 quand sur batterie
            if ($logicalId === 'input_volt' && $Not_Online === 1) {
                $result = 0;
                log::add('Nut_free', 'debug', '[' . $equipement . '] input_volt forcé à 0 (mode batterie)');
            }

            // Conversion secondes → minutes
            if ($logicalId === 'batt_runtime_min' || $logicalId === 'timer_shutdown_min') {
                $result = (int) ((float) $result / 60);
            }

            if ($errorresult !== '') {
                log::add('Nut_free', 'debug', '[' . $equipement . '] ' . $info['name'] . ' : non supporté par l\'UPS');
                $cmd = $this->getCmd(null, $logicalId);
                if (is_object($cmd)) {
                    $cmd->setIsVisible(0);
                    $cmd->setEqLogic_id($this->getId());
                    $cmd->save();
                }
            } else {
                log::add('Nut_free', 'debug', '[' . $equipement . '] ' . $info['name'] . ' : ' . $result);
                $cmd = $this->getCmd(null, $logicalId);
                if (is_object($cmd)) {
                    $cmd->event($result);
                }
            }
        }

        log::add('Nut_free', 'debug', '--- [' . $equipement . '] Fin collecte NUT ---');
    }

    public static function deamon_info() {
        $return = array('log' => 'Nut_free_daemon', 'state' => 'nok', 'launchable' => 'ok');
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pidFile)) {
            if (@posix_getsid(trim(file_get_contents($pidFile)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pidFile . ' 2>&1 > /dev/null');
            }
        }
        return $return;
    }

    public static function deamon_start() {
        $daemonInfo = self::deamon_info();
        if ($daemonInfo['state'] === 'ok') {
            log::add('Nut_free', 'info', '[DAEMON] Déjà en cours d\'exécution, arrêt préalable');
            self::deamon_stop();
        }

        try {
            self::getPythonDepFromRequirements();
            self::getPyEnvVersion();
            self::getPythonVersion();
        } catch (Exception $e) {
            log::add('Nut_free', 'error', '[DAEMON][START][PythonDep] Exception :: ' . $e->getMessage());
        }

        $python3       = self::VENV_PYTHON;
        $script        = realpath(self::DAEMON_SCRIPT);
        $pidFile       = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        $logLevel      = log::convertLogLevel(log::getLogLevel('Nut_free')) ?: 'error';
        $apiKey        = jeedom::getApiKey('Nut_free');
        $socketPort    = config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT);
        $callbackUrl   = network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/Nut_free/core/php/jeeNut_free.php';
        $cyclePolling  = config::byKey('cyclePolling', 'Nut_free', 60);
        $cycleWatcher  = config::byKey('cycleWatcher', 'Nut_free', 5);
        $cycleFactor   = config::byKey('cycleFactor', 'Nut_free', '1.0');

        if (!file_exists($python3)) {
            log::add('Nut_free', 'error', '[DAEMON] Python venv introuvable : ' . self::VENV_PYTHON);
            return false;
        }
        if (empty($script) || !file_exists($script)) {
            log::add('Nut_free', 'error', '[DAEMON] Script daemon introuvable : ' . self::DAEMON_SCRIPT);
            return false;
        }

        @mkdir(dirname($pidFile), 0755, true);

        $cmd = $python3 . ' ' . escapeshellarg($script)
            . ' --socketport '    . $socketPort
            . ' --callback '      . escapeshellarg($callbackUrl)
            . ' --apikey '        . escapeshellarg($apiKey)
            . ' --loglevel '      . escapeshellarg($logLevel)
            . ' --pluginversion ' . escapeshellarg(config::byKey('pluginVersion', 'Nut_free', '0.0.0'))
            . ' --cyclepolling '  . escapeshellarg($cyclePolling)
            . ' --cyclewatcher '  . escapeshellarg($cycleWatcher)
            . ' --cyclefactor '   . escapeshellarg($cycleFactor)
            . ' --pid '           . escapeshellarg($pidFile)
            . ' >> ' . log::getPathToLog('Nut_free_daemon') . ' 2>&1 &';

        log::add('Nut_free', 'info', '[DAEMON] Démarrage : ' . $cmd);
        exec($cmd);

        // Attente démarrage (max 20s)
        $maxWait = 20;
        $ok = false;
        for ($i = 0; $i < $maxWait; $i++) {
            sleep(1);
            if (self::deamon_info()['state'] === 'ok') {
                $ok = true;
                break;
            }
        }

        if ($ok) {
            log::add('Nut_free', 'info', '[DAEMON] Démarré avec succès');
            // Enregistrer tous les équipements locaux actifs
            foreach (eqLogic::byType('Nut_free') as $eqLogic) {
                if ($eqLogic->getIsEnable() && $eqLogic->getConfiguration('connexionMode', 'nut') === 'nut') {
                    self::sendToDaemon(array(
                        'action' => 'add_device',
                        'device' => array(
                            'eqLogicId'   => $eqLogic->getId(),
                            'host'        => $eqLogic->getConfiguration('addressIp', '127.0.0.1'),
                            'port'        => (int) $eqLogic->getConfiguration('nutPort', 3493),
                            'upsName'     => $eqLogic->getConfiguration('ups', ''),
                            'autoDetect'  => ($eqLogic->getConfiguration('upsAutoSelect', '0') === '0') ? 1 : 0,
                            'nutLogin'    => $eqLogic->getConfiguration('nutLogin', ''),
                            'nutPassword' => $eqLogic->getConfiguration('nutPassword', ''),
                        ),
                    ));
                }
            }
        } else {
            log::add('Nut_free', 'error', '[DAEMON] Timeout au démarrage');
        }

        return $ok;
    }

    public static function deamon_stop() {
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pidFile)) {
            $pid = intval(trim(file_get_contents($pidFile)));
            if ($pid > 0) {
                // SIGTERM uniquement (pas de SIGKILL immédiat) → le daemon gère son arrêt proprement
                system::kill($pid, false);
                // Attendre la fin du processus (max 3s par paliers de 100ms)
                for ($i = 0; $i < 30 && file_exists($pidFile); $i++) {
                    usleep(100000);
                }
            }
            @unlink($pidFile);
        }
        // Filet de sécurité si le processus traîne encore
        system::kill('nutfreed.py');
        system::fuserk(config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT));
        log::add('Nut_free', 'info', '[DAEMON] Arrêté');
    }

    public static function sendToDaemon(array $params) {
        $params['apikey'] = jeedom::getApiKey('Nut_free');
        $payload = json_encode($params);
        $socket  = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            log::add('Nut_free', 'error', '[DAEMON] socket_create erreur');
            return false;
        }
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 3, 'usec' => 0));
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 3, 'usec' => 0));
        if (!@socket_connect($socket, '127.0.0.1', config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT))) {
            log::add('Nut_free', 'warning', '[DAEMON] socket_connect impossible (daemon arrêté ?)');
            socket_close($socket);
            return false;
        }
        $msg = $payload . "\n";
        socket_write($socket, $msg, strlen($msg));
        socket_close($socket);
        log::add('Nut_free', 'debug', '[DAEMON] Envoi : ' . $payload);
        return true;
    }
}

class Nut_freeCmd extends cmd {

/*     * *************************Attributs****************************** */
    public static $_widgetPossibility = array('custom' => false);

/*     * *********************Méthode d'instance************************* */
    public function execute($_options = null) {
        if ($this->getType() !== 'action') {
            throw new Exception(__('Commande non implémentée actuellement', __FILE__));
        }

        $eqLogic        = $this->getEqLogic();
        $connexionType  = $eqLogic->getConfiguration('connexionMode', 'nut');
        $ups            = trim($eqLogic->getConfiguration('ups', ''));
        $sshHostId      = $eqLogic->getConfiguration('SSHHostId', '');
        $logicalId      = $this->getLogicalId();
        $equipement     = $eqLogic->getName();

        // Construire la commande NUT upscmd
        $nutCmd = 'upscmd ' . escapeshellarg($ups) . ' ' . escapeshellarg($logicalId);

        log::add('Nut_free', 'info', '[' . $equipement . '] Action : ' . $nutCmd . ' (mode=' . $connexionType . ')');

        try {
            if ($connexionType === 'nut') {
                $fullCmd = $nutCmd . ' 2>&1';
                $result  = trim((string) exec($fullCmd));
            } else {
                if (!class_exists('sshmanager')) {
                    throw new Exception('Plugin SSH-Manager introuvable - vérifiez les dépendances');
                }
                if (empty($sshHostId)) {
                    throw new Exception('SSHHostId non configuré pour l\'équipement ' . $equipement);
                }
                $result = trim((string) sshmanager::executeCmds($sshHostId, $nutCmd . ' 2>&1'));
            }
        } catch (\Exception $e) {
            log::add('Nut_free', 'error', '[' . $equipement . '] Erreur action ' . $logicalId . ' : ' . $e->getMessage());
            throw $e;
        }

        log::add('Nut_free', 'debug', '[' . $equipement . '] Résultat action ' . $logicalId . ' : ' . $result);
        return $result;
    }
}

?>
