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

    public static function cron() {
        foreach (eqLogic::byType('Nut_free') as $eqLogic) {
            /** @var Nut_free $eqLogic */
            if (!$eqLogic->getIsEnable()) continue;
            $mode = $eqLogic->getConfiguration('connexionMode', 'nut');
            if ($mode === 'nut') {
                // Mode NUT : le daemon gère son propre polling (cyclePolling + statusWatcher).
                continue;
            } else {
                // Mode SSH : collecte via SSH-Manager directement en PHP
                $eqLogic->getInfosSSH();
                $eqLogic->refreshWidget();
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

	public static function getConfigForCommunity() {
		$isSSHMExist = class_exists('sshmanager');

		$CommunityInfo = "```\n";
		$CommunityInfo .= 'Debian : ' . system::getOsVersion() . "\n";
		$CommunityInfo .= 'Plugin NUT Free (Version / Branche) : ' . config::byKey('pluginVersion', 'Nut_free', 'N/A') . ' / ' . config::byKey('pluginBranch', 'Nut_free', 'N/A') . "\n";
		$CommunityInfo .= 'Plugin SSH Manager (Version / Branche) : ' . config::byKey('pluginVersion', 'sshmanager', 'N/A') . ' / ' . config::byKey('pluginBranch', 'sshmanager', 'N/A') . "\n";
		$CommunityInfo .= 'Python : ' . config::byKey('pythonVersion', 'Nut_free', 'N/A') . "\n";
		$CommunityInfo .= 'PyEnv : ' . config::byKey('pyenvVersion', 'Nut_free', 'N/A') . "\n";

		$eqLogics = eqLogic::byType('Nut_free');
		$nbTotal   = count($eqLogics);
		$nbEnabled = count(array_filter($eqLogics, function($eq) { return $eq->getIsEnable(); }));
		$CommunityInfo .= 'Équipements : ' . $nbEnabled . ' actif(s) / ' . $nbTotal . " total\n";

		if (!$isSSHMExist) {
			$CommunityInfo .= "\n";
			$CommunityInfo .= 'Plugin SSH Manager non activé !' . "\n";
		}

		$CommunityInfo .= "```";
		return $CommunityInfo;
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
					'eqName'      => $this->getName(),
					'host'        => $this->getConfiguration('addressIp', '127.0.0.1'),
					'port'        => (int) $this->getConfiguration('nutPort', 3493),
					'upsName'     => $this->getConfiguration('ups', ''),
					'autoDetect'  => ($this->getConfiguration('upsAutoSelect', '0') === '0') ? 1 : 0,
					'nutLogin'    => $this->getConfiguration('nutLogin', ''),
					'nutPassword' => $this->getConfiguration('nutPassword', ''),
				),
			));
		} else {
			// Mode SSH : collecte via SSH-Manager directement en PHP
			$this->getInfosSSH();
			$this->refreshWidget();
		}
	}

	public function postUpdate() {
		static::createCmd($this);
	}

	/**
	 * Crée ou met à jour toutes les commandes d'un équipement (ou de tous).
	 * Idempotent : peut être appelé plusieurs fois sans effet de bord.
	 * Si $eqLogic est null, parcourt tous les équipements NUT Free (utilisé par install/update).
	 * Source de vérité : $commandsConfig (variable locale, pattern DiscordLink).
	 */
	public static function createCmd($eqLogic = null) {
		$commandsConfig = array(
			// Commande action
			'refresh'            => array('name' => 'Rafraîchir',                        'type' => 'action', 'subtype' => 'other',   'isVisible' => 0, 'icon' => '<i class="fas fa-sync-alt icon_green"></i>'),
			// Commandes info
			'device_mfr'         => array('name' => 'Fabricant',              'template_dashboard' => 'line', 'subtype' => 'string', 'nutCmd' => 'device.mfr',         'icon' => '<i class="fas fa-tag icon_green"></i>'),
			'device_model'       => array('name' => 'Modèle',                                                   'subtype' => 'string', 'nutCmd' => 'device.model',        'icon' => '<i class="fas fa-tag icon_blue"></i>'),
			'ups_serial'         => array('name' => 'Numéro Série',                                           'subtype' => 'string', 'nutCmd' => 'ups.serial',          'icon' => '<i class="fas fa-barcode icon_green"></i>'),
			'ups_status'         => array('name' => 'Code NUT',                                                 'subtype' => 'string', 'nutCmd' => 'ups.status',          'icon' => '<i class="fas fa-code icon_green"></i>'),
			'ups_status_label'   => array('name' => 'Statut Onduleur',                                           'subtype' => 'string', 'nutCmd' => 'ups.status',          'icon' => '<i class="fas fa-plug icon_green"></i>'),
			'input_voltage'      => array('name' => 'Tension Entrée',            'unite' => 'V',                 'nutCmd' => 'input.voltage',       'icon' => '<i class="fas fa-bolt icon_green"></i>'),
			'input_freq'         => array('name' => 'Fréquence Entrée',          'unite' => 'Hz',                'nutCmd' => 'input.frequency',     'icon' => '<i class="fas fa-wave-square icon_green"></i>'),
			'output_voltage'     => array('name' => 'Tension Sortie',            'unite' => 'V',                 'nutCmd' => 'output.voltage',      'icon' => '<i class="fas fa-bolt icon_green"></i>'),
			'output_freq'        => array('name' => 'Fréquence Sortie',          'unite' => 'Hz',                'nutCmd' => 'output.frequency',    'icon' => '<i class="fas fa-wave-square icon_green"></i>'),
			'output_power'       => array('name' => 'Puissance Sortie',          'unite' => 'VA',                'nutCmd' => 'ups.power',           'icon' => '<i class="fas fa-tachometer-alt icon_green"></i>'),
			'output_real_power'  => array('name' => 'Puissance Sortie Réelle',   'unite' => 'W',                 'nutCmd' => 'ups.realpower',       'icon' => '<i class="fas fa-tachometer-alt icon_blue"></i>'),
			'batt_charge'        => array('name' => 'Charge Batterie',           'unite' => '%',                 'nutCmd' => 'battery.charge',      'icon' => '<i class="fas fa-battery-three-quarters icon_green"></i>'),
			'batt_voltage'       => array('name' => 'Tension Batterie',          'unite' => 'V',                 'nutCmd' => 'battery.voltage',     'icon' => '<i class="fas fa-bolt icon_green"></i>'),
			'batt_temp'          => array('name' => 'Température Batterie',      'unite' => '°C',                'nutCmd' => 'battery.temperature', 'icon' => '<i class="fas fa-thermometer-half icon_blue"></i>'),
			'ups_temp'           => array('name' => 'Température Onduleur',      'unite' => '°C',                'nutCmd' => 'ups.temperature',     'icon' => '<i class="fas fa-thermometer-half icon_green"></i>'),
			'ups_load'           => array('name' => 'Charge Onduleur',           'unite' => '%',                 'nutCmd' => 'ups.load',            'icon' => '<i class="fas fa-chart-bar icon_green"></i>'),
			'batt_runtime'       => array('name' => 'Autonomie Batterie',        'unite' => 'sec',                 'nutCmd' => 'battery.runtime',     'icon' => '<i class="fas fa-clock icon_blue"></i>'),
			'batt_runtime_min'   => array('name' => 'Autonomie Batterie (min)',  'unite' => 'min',               'nutCmd' => 'battery.runtime',     'icon' => '<i class="fas fa-clock icon_green"></i>'),
			'timer_shutdown'     => array('name' => 'Minuterie Arrêt',           'unite' => 'sec',                 'nutCmd' => 'ups.timer.shutdown',  'icon' => '<i class="fas fa-power-off icon_blue"></i>'),
			'timer_shutdown_min' => array('name' => 'Minuterie Arrêt (min)',     'unite' => 'min',               'nutCmd' => 'ups.timer.shutdown',  'icon' => '<i class="fas fa-power-off icon_green"></i>'),
			'beeper_status'      => array('name' => 'Beeper',                                                'subtype' => 'string', 'nutCmd' => 'ups.beeper.status',   'icon' => '<i class="fas fa-volume-up icon_green"></i>'),
			// Commandes actions instcmd
			'beeper_disable'     => array('name' => 'Désactiver Beeper',    'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'nutCmd' => 'beeper.disable',            'icon' => '<i class="fas fa-volume-mute icon_orange"></i>'),
			'beeper_enable'      => array('name' => 'Activer Beeper',       'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'nutCmd' => 'beeper.enable',             'icon' => '<i class="fas fa-volume-up icon_green"></i>'),
			'beeper_mute'        => array('name' => 'Beeper Silencieux',    'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'nutCmd' => 'beeper.mute',              'icon' => '<i class="fas fa-bell-slash icon_blue"></i>'),
			'test_battery_quick' => array('name' => 'Test Batterie Rapide', 'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'nutCmd' => 'test.battery.start.quick', 'icon' => '<i class="fas fa-vial icon_blue"></i>'),
			'test_battery_stop'  => array('name' => 'Arrêt Test Batterie',  'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'nutCmd' => 'test.battery.stop',        'icon' => '<i class="fas fa-stop-circle icon_red"></i>'),
			// Résultat dernière commande instcmd
			'cmd_result'         => array('name' => 'Retour Commande',       'subtype' => 'string', 'isVisible' => 1,                                                           'icon' => '<i class="fas fa-terminal icon_blue"></i>'),
		);

		$targets = is_object($eqLogic) ? array($eqLogic) : eqLogic::byType('Nut_free');
		foreach ($targets as $eq) {
			$order = 0;
			foreach ($commandsConfig as $logicalId => $info) {
				$cmd = $eq->getCmd(null, $logicalId);
				if (!is_object($cmd)) {
					$cmd = new Nut_freeCmd();
					$cmd->setLogicalId($logicalId);
				}
				$cmd->setEqLogic_id($eq->getId());
				$cmd->setName(__($info['name'], __FILE__));
				$cmd->setType($info['type'] ?? 'info');
				$cmd->setSubType($info['subtype'] ?? 'numeric');
				$cmd->setOrder($order);
				// Toujours écraser l'unité et la variable NUT pour propager les changements lors des mises à jour
				$cmd->setUnite($info['unite'] ?? '');
				$cmd->setConfiguration('nutCmd', $info['nutCmd'] ?? '');
				if (isset($info['icon'])) {
					$cmd->setDisplay('icon', $info['icon']);
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

	public static function translateUpsStatus(string $raw): string {
		$map = array(
			'OL'      => 'Sur Secteur',
			'OB'      => 'Sur Batterie',
			'LB'      => 'Batterie Faible',
			'HB'      => 'Batterie Chargée',
			'RB'      => 'Remplacer Batterie',
			'CHRG'    => 'En Charge',
			'DISCHRG' => 'En Décharge',
			'BYPASS'  => 'Bypass',
			'CAL'     => 'Calibration',
			'OFF'     => 'Hors Tension',
			'OVER'    => 'Surchargé',
			'TRIM'    => 'Régulation Basse',
			'BOOST'   => 'Régulation Haute',
			'FSD'     => 'Arrêt Forcé',
			'ALARM'   => 'Alarme',
		);
		$flags  = array_filter(explode(' ', strtoupper(trim($raw))));
		$labels = array();
		foreach ($flags as $flag) {
			$labels[] = isset($map[$flag]) ? $map[$flag] : $flag;
		}
		return implode(' / ', $labels) ?: $raw;
	}

	public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$_version = jeedom::versionAlias($_version);

		foreach ($this->getCmd() as $cmd) {
			$logicalId = $cmd->getLogicalId();
			$replace['#' . $logicalId . 'id#']       = $cmd->getId();
			$replace['#' . $logicalId . '_display#'] = $cmd->getIsVisible() ? 'block' : 'none';
			$replace['#' . $logicalId . '_icon#']    = !empty($cmd->getDisplay('icon')) ? $cmd->getDisplay('icon') : '<i class="fas fa-circle"></i>';
			$replace['#' . $logicalId . '_collect#'] = $cmd->getCollectDate() ?: '-';
			$replace['#' . $logicalId . '_value#']   = $cmd->getValueDate() ?: '-';
			$replace['#' . $logicalId . '_name#']    = $cmd->getName();
			$replace['#' . $logicalId . '_unite#']       = $cmd->getUnite();
			$replace['#' . $logicalId . '_historized#'] = $cmd->getIsHistorized() ? 'history cursor' : '';
			// Ne pas appeler execCmd() sur les commandes action (refresh, etc.)
			if ($cmd->getType() === 'info') {
				$replace['#' . $logicalId . '#'] = $cmd->execCmd();
			}
		}

		$html = template_replace($replace, getTemplate('core', $_version, 'Nut_free', 'Nut_free'));
		return $html;
	}
	
    public function getInfosSSH() {
        if (!$this->getIsEnable()) return;

        $equipment      = $this->getName();
        $upsAutoSelect = $this->getConfiguration('upsAutoSelect', '0');
        $ups           = trim($this->getConfiguration('ups', ''));
        $sshHostId     = $this->getConfiguration('SSHHostId', '');

        log::add('Nut_free', 'debug', '--- [' . $equipment . '] Début collecte NUT via SSH ---');

        // --- Vérification SSH-Manager ---
        if (!class_exists('sshmanager')) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Plugin SSH-Manager introuvable - vérifiez les dépendances');
            return false;
        }
        if (empty($sshHostId)) {
            log::add('Nut_free', 'error', '[' . $equipment . '] SSHHostId non configuré');
            return false;
        }

        // --- Résolution du nom de l'UPS (auto-détection si upsAutoSelect = '0') ---
        if ($upsAutoSelect === '0' || $ups === '') {
            try {
                $upsListCmd = "upsc -l 2>&1 | grep -v '^Init SSL'";
                $ups = trim((string) sshmanager::executeCmds($sshHostId, $upsListCmd));
                log::add('Nut_free', 'debug', '[' . $equipment . '] UPS auto-détecté : ' . $ups);
            } catch (\Exception $e) {
                log::add('Nut_free', 'error', '[' . $equipment . '] Auto-détection UPS échouée : ' . $e->getMessage());
                return false;
            }
        } else {
            log::add('Nut_free', 'debug', '[' . $equipment . '] UPS manuel : ' . $ups);
        }

        if (empty($ups)) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Impossible de déterminer le nom de l\'UPS');
            return false;
        }

        // --- Collecte des valeurs NUT via SSH ---
        $notOnline = 0;

        foreach ($this->getCmd() as $cmd) {
            $logicalId = $cmd->getLogicalId();
            $nutVar    = $cmd->getConfiguration('nutCmd', '');
            if (empty($nutVar)) continue;

            $result = '';
            try {
                $cmdLine = 'upsc ' . escapeshellarg($ups) . ' ' . escapeshellarg($nutVar) . " 2>&1 | grep -v '^Init SSL'";
                $result  = trim((string) sshmanager::executeCmds($sshHostId, $cmdLine));
            } catch (\Exception $e) {
                log::add('Nut_free', 'warning', '[' . $equipment . '] ' . $cmd->getName() . ' erreur : ' . $e->getMessage());
                continue;
            }

            $errorResult = (strpos($result, 'not supported by UPS') !== false) ? $result : '';

            // Mode ligne / batterie
            if ($logicalId === 'ups_status') {
                $notOnline = (stripos($result, 'OL') === false) ? 1 : 0;
                log::add('Nut_free', 'debug', '[' . $equipment . '] ups_status=' . $result . ' (notOnline=' . $notOnline . ')');
            }

            // Statut UPS traduit en français
            if ($logicalId === 'ups_status_label') {
                $result = Nut_free::translateUpsStatus($result);
            }

            // Tension entrée forcée à 0 quand sur batterie
            if ($logicalId === 'input_voltage' && $notOnline === 1) {
                $result = 0;
                log::add('Nut_free', 'debug', '[' . $equipment . '] input_voltage forcé à 0 (mode batterie)');
            }

            // Conversion secondes → minutes
            if ($logicalId === 'batt_runtime_min' || $logicalId === 'timer_shutdown_min') {
                $result = round((float) $result / 60, 2);
            }

            if ($errorResult !== '') {
                log::add('Nut_free', 'debug', '[' . $equipment . '] ' . $cmd->getName() . ' : non supporté par l\'UPS');
                $cmd->setIsVisible(0);
                $cmd->setEqLogic_id($this->getId());
                $cmd->save();
            } else {
                log::add('Nut_free', 'debug', '[' . $equipment . '] ' . $cmd->getName() . ' : ' . $result);
                $cmd->event($result);
            }
        }

        log::add('Nut_free', 'debug', '--- [' . $equipment . '] Fin collecte NUT ---');
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
            // Enregistrer tous les équipements NUT actifs
            foreach (eqLogic::byType('Nut_free') as $eqLogic) {
                if ($eqLogic->getIsEnable() && $eqLogic->getConfiguration('connexionMode', 'nut') === 'nut') {
                    self::sendToDaemon(array(
                        'action' => 'add_device',
                        'device' => array(
                            'eqLogicId'   => $eqLogic->getId(),
                            'eqName'      => $eqLogic->getName(),
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

        /** @var Nut_free $eqLogic */
        $eqLogic        = $this->getEqLogic();
        $connexionType  = $eqLogic->getConfiguration('connexionMode', 'nut');
        $ups            = trim($eqLogic->getConfiguration('ups', ''));
        $sshHostId      = $eqLogic->getConfiguration('SSHHostId', '');
        $logicalId      = $this->getLogicalId();
        $equipment     = $eqLogic->getName();

        // Commande spéciale : rafraîchissement des données
        if ($logicalId === 'refresh') {
            log::add('Nut_free', 'info', '[' . $equipment . '] Refresh demandé (mode=' . $connexionType . ')');
            if ($connexionType === 'nut') {
                Nut_free::sendToDaemon(array(
                    'action'    => 'query_now',
                    'eqLogicId' => $eqLogic->getId(),
                ));
            } else {
                $eqLogic->getInfosSSH();
                $eqLogic->refreshWidget();
            }
            return;
        }

        // Commandes instcmd NUT
        $nutInstCmd = $this->getConfiguration('nutCmd', '');
        if (empty($nutInstCmd)) {
            throw new Exception(__('nutCmd non configuré pour la commande ' . $logicalId, __FILE__));
        }

        log::add('Nut_free', 'info', '[' . $equipment . '] instcmd : ' . $nutInstCmd . ' (mode=' . $connexionType . ')');

        try {
            if ($connexionType === 'nut') {
                // Mode NUT : déléguer au daemon via PyNUT RunUPSCommand (résultat renvoyé en asynchrone via callback)
                Nut_free::sendToDaemon(array(
                    'action'     => 'instcmd',
                    'eqLogicId'  => $eqLogic->getId(),
                    'nutInstCmd' => $nutInstCmd,
                ));
            } else {
                // Mode SSH : exécuter upscmd sur le serveur distant
                if (!class_exists('sshmanager')) {
                    throw new Exception('Plugin SSH-Manager introuvable - vérifiez les dépendances');
                }
                if (empty($sshHostId)) {
                    throw new Exception('SSHHostId non configuré pour l\'équipement ' . $equipment);
                }
                if (empty($ups)) {
                    throw new Exception('Nom UPS non configuré pour l\'équipement ' . $equipment);
                }
                $cmd    = 'upscmd ' . escapeshellarg($ups) . ' ' . escapeshellarg($nutInstCmd) . ' 2>&1';
                $result = trim((string) sshmanager::executeCmds($sshHostId, $cmd));
                log::add('Nut_free', 'debug', '[' . $equipment . '] Résultat instcmd ' . $nutInstCmd . ' : ' . $result);
                // Stocker le résultat dans la commande cmd_result
                $cmdResult = $eqLogic->getCmd('info', 'cmd_result');
                if (is_object($cmdResult)) {
                    $cmdResult->event($nutInstCmd . ' → ' . ($result ?: 'OK'));
                }
            }
        } catch (\Exception $e) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Erreur instcmd ' . $nutInstCmd . ' : ' . $e->getMessage());
            throw $e;
        }
    }
}

?>
