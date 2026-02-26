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
            }
            // Mode SSH : collecte via SSH-Manager directement en PHP
            $eqLogic->getInfosSSH();
            $eqLogic->refreshWidget();
        }
    }

	
	public static function getPluginBranch() {
		$pluginBranch = 'N/A';
		try {
			$_update = update::byLogicalId('Nut_free');
			$pluginBranch = $_update->getConfiguration('version', 'N/A') . ' (' . $_update->getSource() . ')';
		} catch (\Throwable $e) {
			log::add('Nut_free', 'warning', '[BRANCH] Get ERROR :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[BRANCH] PluginBranch :: ' . $pluginBranch);
		return $pluginBranch;
	}

	public static function getPluginVersion() {
		$pluginVersion = '0.0.0';
		try {
			$jsonPath = dirname(__FILE__) . '/../../plugin_info/info.json';
			if (!file_exists($jsonPath)) {
				log::add('Nut_free', 'warning', '[VERSION] fichier info.json manquant');
				return $pluginVersion;
			}
			$data = json_decode((string) file_get_contents($jsonPath), true);
			if (!is_array($data)) {
				log::add('Nut_free', 'warning', '[VERSION] Impossible de décoder le fichier info.json');
				return $pluginVersion;
			}
			$pluginVersion = $data['pluginVersion'] ?? '0.0.0';
		} catch (\Throwable $e) {
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

		if (file_exists($return['progress_file'])) {
			$return['state'] = 'in_progress';
		} else {
			if (!file_exists(self::VENV_PYTHON)) {
				log::add($_logName, 'debug', '[DepInfo][ERROR] Python venv introuvable');
				$return['state'] = 'nok';
			} elseif ((int) exec(self::VENV_PYTHON . ' -m pip freeze | grep -Eiwc "' . config::byKey('pythonDepString', 'Nut_free', '', true) . '"') < (int) config::byKey('pythonDepNum', 'Nut_free', 0, true)) {
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
		} catch (\Throwable $e) {
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
				$pythonVersion = exec(system::getCmdSudo() . self::VENV_PYTHON . " --version | awk '{ print $2 }'") ?: '0.0.0';
				config::save('pythonVersion', $pythonVersion, 'Nut_free');
			} else {
				log::add('Nut_free', 'error', '[Python-Version] Python venv introuvable :: ' . self::VENV_PYTHON);
			}
		} catch (\Throwable $e) {
			log::add('Nut_free', 'error', '[Python-Version] Exception :: ' . $e->getMessage());
		}
		log::add('Nut_free', 'info', '[Python-Version] PythonVersion (venv) :: ' . $pythonVersion);
		return $pythonVersion;
	}

	public static function getPyEnvVersion() {
		$pyenvVersion = '0.0.0';
		try {
			if (file_exists(self::PYENV_PATH)) {
				$pyenvVersion = exec(system::getCmdSudo() . self::PYENV_PATH . " --version | awk '{ print $2 }'") ?: '0.0.0';
				config::save('pyenvVersion', $pyenvVersion, 'Nut_free');
			} elseif (file_exists(self::VENV_PYTHON)) {
				$pythonPyEnvInUse = exec(system::getCmdSudo() . 'dirname $(readlink ' . self::VENV_PYTHON . ') | grep -Ewc "opt/pyenv"') === '1';
				if (!$pythonPyEnvInUse) {
					$pyenvVersion = '-';
					config::save('pyenvVersion', $pyenvVersion, 'Nut_free');
				}
			} else {
				log::add('Nut_free', 'error', '[PyEnv-Version] PyEnv File :: KO');
			}
		} catch (\Throwable $e) {
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

		if (config::byKey('debugInstallUpdates', 'Nut_free') === '1') {
			$script_sysUpdates = 1;
			config::save('debugInstallUpdates', '0', 'Nut_free');
		}
		if (config::byKey('debugRestorePyEnv', 'Nut_free') === '1') {
			$script_restorePyEnv = 1;
			config::save('debugRestorePyEnv', '0', 'Nut_free');
		}
		if (config::byKey('debugRestoreVenv', 'Nut_free') === '1') {
			$script_restoreVenv = 1;
			config::save('debugRestoreVenv', '0', 'Nut_free');
		}

		return array(
			'script' => __DIR__ . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency' . ' ' . $script_sysUpdates . ' ' . $script_restorePyEnv . ' ' . $script_restoreVenv,
			'log'    => log::getPathToLog(__CLASS__ . '_update'),
		);
	}

	protected static function buildDevicePayload(Nut_free $eq): array {
		return [
			'eqLogicId'   => $eq->getId(),
			'eqName'      => $eq->getName(),
			'host'        => $eq->getConfiguration('addressIp', '127.0.0.1'),
			'port'        => (int) $eq->getConfiguration('nutPort', 3493),
			'upsName'     => $eq->getConfiguration('ups', ''),
			'autoDetect'  => ($eq->getConfiguration('upsAutoSelect', '0') === '0') ? 1 : 0,
			'nutUsername' => $eq->getConfiguration('nutUsername', ''),
			'nutPassword' => $eq->getConfiguration('nutPassword', ''),
		];
	}

	public function postSave() {
		static::createCmd($this);

		// Déclencher une première collecte selon le mode de connexion
		$mode = $this->getConfiguration('connexionMode', 'nut');
		if ($mode === 'nut') {
			self::sendToDaemon(array(
				'action' => 'add_device',
				'device' => self::buildDevicePayload($this),
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
			// Action virtuelle
			'refresh'              => array('name' => 'Rafraîchir',                    'type' => 'action', 'subtype' => 'other', 'isVisible' => 0, 'icon' => '<i class="fas fa-sync-alt icon_green"></i>'),
			// Identification (quasi-universelles)
			'device_mfr'           => array('name' => 'Fabricant',               'subtype' => 'string', 'nutCmd' => 'device.mfr',      'icon' => '<i class="fas fa-tag icon_green"></i>'),
			'device_model'         => array('name' => 'Modèle',                                                   'subtype' => 'string', 'nutCmd' => 'device.model',    'icon' => '<i class="fas fa-tag icon_blue"></i>'),
			'ups_serial'           => array('name' => 'Numéro Série',                                           'subtype' => 'string', 'nutCmd' => 'ups.serial',      'icon' => '<i class="fas fa-barcode icon_green"></i>'),
			// Statut (seule var obligatoire NUT)
			'ups_status'           => array('name' => 'Code NUT',                                                    'subtype' => 'string', 'nutCmd'      => 'ups.status',        'icon' => '<i class="fas fa-code icon_green"></i>'),
			'ups_status_label'     => array('name' => 'Statut Onduleur',                                             'subtype' => 'string', 'derivedFrom' => 'ups_status',        'icon' => '<i class="fas fa-plug icon_green"></i>'),
			// Métriques quasi-universelles
			'ups_load'             => array('name' => 'Charge Onduleur',          'unite' => '%',   'nutCmd'      => 'ups.load',          'icon' => '<i class="fas fa-chart-bar icon_green"></i>'),
			'battery_charge'       => array('name' => 'Charge Batterie',          'unite' => '%',   'nutCmd'      => 'battery.charge',    'icon' => '<i class="fas fa-battery-three-quarters icon_green"></i>'),
			'battery_runtime'      => array('name' => 'Autonomie Batterie',       'unite' => 'sec', 'nutCmd'      => 'battery.runtime',   'icon' => '<i class="fas fa-clock icon_blue"></i>'),
			'battery_runtime_min'  => array('name' => 'Autonomie Batterie (min)', 'unite' => 'min', 'derivedFrom' => 'battery_runtime',   'icon' => '<i class="fas fa-clock icon_green"></i>'),
			// Virtuelle
			'cmd_result'           => array('name' => 'Retour Commande',          'subtype' => 'string', 'isVisible' => 1,             'icon' => '<i class="fas fa-terminal icon_blue"></i>'),
		);

		$targets = is_object($eqLogic) ? array($eqLogic) : eqLogic::byType('Nut_free');
		foreach ($targets as $eq) {
			$order = 0;
			foreach ($commandsConfig as $logicalId => $info) {
				$cmd   = $eq->getCmd(null, $logicalId);
				$isNew = !is_object($cmd);
				if ($isNew) {
					$cmd = new Nut_freeCmd();
					$cmd->setLogicalId($logicalId);
				}
				$cmd->setEqLogic_id($eq->getId());
				// Données techniques : toujours propagées (cohérence après mise à jour plugin)
				$cmd->setType($info['type'] ?? 'info');
				$cmd->setSubType($info['subtype'] ?? 'numeric');
				$cmd->setConfiguration('nutCmd',      $info['nutCmd']      ?? '');
				$cmd->setConfiguration('derivedFrom', $info['derivedFrom'] ?? '');
				// Données utilisateur : initialisées à la création uniquement (l'utilisateur est maître)
				if ($isNew) {
					$cmd->setName(__($info['name'], __FILE__));
					$cmd->setOrder($order);
					$cmd->setUnite($info['unite'] ?? '');
					if (isset($info['icon'])) {
						$cmd->setDisplay('icon', $info['icon']);
					}
					if (($info['type'] ?? 'info') === 'info') {
						$cmd->setTemplate('dashboard', 'Nut_free');
						$cmd->setTemplate('mobile', 'Nut_free');
					}
					if (isset($info['isVisible'])) {
						$cmd->setIsVisible($info['isVisible']);
					}
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

	/**
	 * Crée ou met à jour les commandes dynamiques découvertes via "Synchroniser avec l'onduleur".
	 * Toutes sont marquées isDynamic=1 pour pouvoir être supprimées par cleanDynamicCmds().
	 * Les logicalIds sont directs (pas de préfixe) : la distinction statique/dynamique se fait via isDynamic.
	 * Pour les vars RW, l'action d'écriture a le logicalId <logicalId>_set.
	 * Pour toute var avec unit='sec', une commande jumelle <logicalId>_min est aussi créée.
	 *
	 * @param Nut_free $eqLogic   Équipement cible
	 * @param array    $payload   {info_vars: [...], rw_vars: [...], instcmds: [...]}
	 */
	public static function createDynamicCmd(Nut_free $eqLogic, array $payload): void {
		$eqId  = $eqLogic->getId();
		$order = 1000; // après les commandes statiques (ordre < 100)

		// Helper interne : crée ou met à jour une commande info dynamique
		// $derivedFrom : logicalId de la commande source si cette commande est calculée (pas de nutCmd direct)
		$makeInfo = function(string $logicalId, string $name, string $subtype, string $unit, string $nutVar, string $icon, string $value, string $derivedFrom = '') use ($eqLogic, &$order): void {
			$cmd   = $eqLogic->getCmd(null, $logicalId);
			$isNew = !is_object($cmd);
			if ($isNew) {
				$cmd = new Nut_freeCmd();
				$cmd->setLogicalId($logicalId);
			}
			$cmd->setEqLogic_id($eqLogic->getId());
			// Données techniques : toujours propagées
			$cmd->setType('info');
			$cmd->setSubType($subtype);
			$cmd->setConfiguration('nutCmd',      $nutVar);
			$cmd->setConfiguration('derivedFrom', $derivedFrom);
			// isDynamic : positionné seulement à la création pour ne pas écraser les commandes statiques préexistantes
			if ($isNew) {
				$cmd->setConfiguration('isDynamic', 1);
			}
			// Données utilisateur : initialisées à la création uniquement
			if ($isNew) {
				$cmd->setName($name);
				$cmd->setUnite($unit);
				$cmd->setDisplay('icon', '<i class="' . htmlspecialchars($icon, ENT_QUOTES) . '"></i>');
				$cmd->setTemplate('dashboard', 'Nut_free');
				$cmd->setTemplate('mobile', 'Nut_free');
				$cmd->setIsVisible(0);
				$cmd->setOrder($order);
			}
			$order++;
			try {
				$cmd->save();
				if ($value !== '') {
					$cmd->event($value);
				}
			} catch (\Throwable $th) {
				log::add('Nut_free', 'error', '[createDynamicCmd] Erreur sauvegarde ' . $logicalId . ' : ' . $th->getMessage());
			}
		};

		// Helper : calcule la valeur _min depuis une valeur en secondes
		$toMin = static function(string $v): string {
			if (trim($v) === '-1' || $v === '') return $v;
			return (string) round((float) $v / 60, 2);
		};

		// --- info_vars : commandes info lecture seule ---
		foreach ($payload['info_vars'] ?? [] as $entry) {
			$logicalId = $entry['logicalId'];
			$rawVal    = isset($entry['value']) ? (string) $entry['value'] : '';
			$unit      = $entry['unit'] ?? '';
			$makeInfo($logicalId, $entry['name'], $entry['subtype'] ?? 'string', $unit, $entry['nut_var'], $entry['icon'] ?? 'fas fa-circle', $rawVal);
			if ($unit === 'sec') {
				// Commande dérivée : pas de nutCmd propre, valeur calculée depuis $logicalId
				$makeInfo($logicalId . '_min', $entry['name'] . ' (min)', 'numeric', 'min', '', $entry['icon'] ?? 'fas fa-clock', $toMin($rawVal), $logicalId);
			}
		}

		// --- rw_vars : commande info (valeur courante) + commande action écriture (<logicalId>_set) ---
		foreach ($payload['rw_vars'] ?? [] as $entry) {
			$logicalId = $entry['logicalId'];
			$rawVal    = isset($entry['value']) ? (string) $entry['value'] : '';
			$unit      = $entry['unit'] ?? '';
			// Info
			$makeInfo($logicalId, $entry['name'], $entry['subtype'] ?? 'string', $unit, $entry['nut_var'], $entry['icon'] ?? 'fas fa-sliders-h icon_blue', $rawVal);
			if ($unit === 'sec') {
				// Commande dérivée : pas de nutCmd propre, valeur calculée depuis $logicalId
				$makeInfo($logicalId . '_min', $entry['name'] . ' (min)', 'numeric', 'min', '', 'fas fa-clock', $toMin($rawVal), $logicalId);
			}
			// Action écriture
			$setId    = $logicalId . '_set';
			$cmdRw    = $eqLogic->getCmd(null, $setId);
			$isNewRw  = !is_object($cmdRw);
			if ($isNewRw) {
				$cmdRw = new Nut_freeCmd();
				$cmdRw->setLogicalId($setId);
			}
			$cmdRw->setEqLogic_id($eqId);
			// Données techniques : toujours propagées
			$cmdRw->setType('action');
			$cmdRw->setSubType(($entry['subtype'] === 'numeric') ? 'slider' : 'message');
			$cmdRw->setConfiguration('nutRwVar', $entry['nut_var']);
			// isDynamic : positionné seulement à la création pour ne pas écraser les commandes statiques préexistantes
			if ($isNewRw) {
				$cmdRw->setConfiguration('isDynamic', 1);
			}
			// Données utilisateur : initialisées à la création uniquement
			if ($isNewRw) {
				$cmdRw->setName(__('Modifier', __FILE__) . ' ' . $entry['name']);
				$cmdRw->setUnite($unit);
				$cmdRw->setDisplay('icon', '<i class="fas fa-pencil-alt icon_orange"></i>');
				$cmdRw->setIsVisible(0);
				$cmdRw->setOrder($order);
			}
			$order++;
			try {
				$cmdRw->save();
			} catch (\Throwable $th) {
				log::add('Nut_free', 'error', '[createDynamicCmd] Erreur sauvegarde rw ' . $setId . ' : ' . $th->getMessage());
			}
		}

		// --- instcmds : commandes action (exécution) ---
		foreach ($payload['instcmds'] ?? [] as $entry) {
			$logicalId = $entry['logicalId'];
			$cmd   = $eqLogic->getCmd(null, $logicalId);
			$isNew = !is_object($cmd);
			if ($isNew) {
				$cmd = new Nut_freeCmd();
				$cmd->setLogicalId($logicalId);
			}
			$cmd->setEqLogic_id($eqId);
			// Données techniques : toujours propagées
			$cmd->setType('action');
			$cmd->setSubType('other');
			$cmd->setConfiguration('nutCmd', $entry['nut_cmd']);
			// isDynamic : positionné seulement à la création pour ne pas écraser les commandes statiques préexistantes
			if ($isNew) {
				$cmd->setConfiguration('isDynamic', 1);
			}
			// Données utilisateur : initialisées à la création uniquement
			if ($isNew) {
				$cmd->setName($entry['name']);
				$cmd->setDisplay('icon', '<i class="' . htmlspecialchars($entry['icon'] ?? 'fas fa-terminal icon_blue', ENT_QUOTES) . '"></i>');
				$cmd->setIsVisible(0);
				$cmd->setOrder($order);
			}
			$order++;
			try {
				$cmd->save();
			} catch (\Throwable $th) {
				log::add('Nut_free', 'error', '[createDynamicCmd] Erreur sauvegarde instcmd ' . $logicalId . ' : ' . $th->getMessage());
			}
		}

		$nInfo    = count($payload['info_vars'] ?? []);
		$nRw      = count($payload['rw_vars'] ?? []);
		$nInstcmd = count($payload['instcmds'] ?? []);
		log::add('Nut_free', 'info', '[createDynamicCmd] eqLogic ' . $eqId . ' :: ' . $nInfo . ' info_vars, ' . $nRw . ' rw_vars (+' . $nRw . ' actions), ' . $nInstcmd . ' instcmds');
		$eqLogic->setConfiguration('discover_error', '');
		$eqLogic->setConfiguration('discover_status', 'done');
		$eqLogic->save();
		$eqLogic->refreshWidget();
	}

	/**
	 * Supprime toutes les commandes dynamiques de cet équipement (isDynamic=1).
	 * À appeler avant une re-synchronisation ou depuis le bouton "Supprimer commandes dynamiques".
	 */
	public function cleanDynamicCmds(): void {
		$count = 0;
		foreach ($this->getCmd() as $cmd) {
			if ($cmd->getConfiguration('isDynamic', 0)) {
				try {
					$cmd->remove();
					$count++;
				} catch (\Throwable $th) {
					log::add('Nut_free', 'error', '[cleanDynamicCmds] Erreur suppression ' . $cmd->getLogicalId() . ' : ' . $th->getMessage());
				}
			}
		}
		$this->setConfiguration('discover_status', '');
		$this->setConfiguration('discover_error', '');
		$this->save();
		log::add('Nut_free', 'info', '[cleanDynamicCmds] ' . $count . ' commandes dynamiques supprimées pour eqLogic ' . $this->getId());
	}

	/*
	 * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
	 */
	public function decrypt() {
		$this->setConfiguration('nutUsername', utils::decrypt($this->getConfiguration('nutUsername')));
		$this->setConfiguration('nutPassword', utils::decrypt($this->getConfiguration('nutPassword')));
	}
	public function encrypt() {
		$this->setConfiguration('nutUsername', utils::encrypt($this->getConfiguration('nutUsername')));
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
			$labels[] = $map[$flag] ?? $flag;
		}
		return implode(' / ', $labels) ?: $raw;
	}

	public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$_version = jeedom::versionAlias($_version);

		// Format du title selon la version : mobile ne supporte pas le HTML dans les tooltips
		$isMobile = ($_version === 'mobile');

		$cmdsHtml = '';
		foreach ($this->getCmd() as $cmd) {
			$logicalId = $cmd->getLogicalId();
			$cmdId     = $cmd->getId();

			// Tokens nécessaires au header (id du refresh + valeurs pour le script icônes)
			$replace['#' . $logicalId . 'id#'] = $cmdId;
			$rawValue = null;
			if ($cmd->getType() === 'info') {
				$rawValue = $cmd->execCmd();
				$replace['#' . $logicalId . '#'] = $rawValue;
			}

			// Corps : refresh géré dans le header ; commandes masquées et actions ignorées
			if ($logicalId === 'refresh' || !$cmd->getIsVisible() || $cmd->getType() !== 'info') {
				continue;
			}

			$name    = $cmd->getName();
			$nameEnc = htmlspecialchars($name, ENT_QUOTES);
			$icon    = !empty($cmd->getDisplay('icon')) ? $cmd->getDisplay('icon') : '<i class="fas fa-circle"></i>';

			if ($cmd->getType() === 'info') {
				$value      = htmlspecialchars((string) $rawValue, ENT_QUOTES);
				$unite      = htmlspecialchars($cmd->getUnite(), ENT_QUOTES);
				$historized = $cmd->getIsHistorized() ? 'history cursor' : '';
				$dateVal    = $cmd->getValueDate() ?: '-';
				$dateCol    = $cmd->getCollectDate() ?: '-';
				if ($isMobile) {
					$titleAttr = $nameEnc . ' || Date valeur : ' . $dateVal . ' || Date collecte : ' . $dateCol;
				} else {
					$titleAttr = $nameEnc . '<br><i>Date de valeur : ' . $dateVal . '<br>Date de collecte : ' . $dateCol . '</i>';
				}
				$cmdsHtml  .= '<div class="tooltips" data-cmd_id="' . $cmdId . '">' . "\n\t\t";
				$cmdsHtml  .= '<span title="' . $titleAttr . '"';
				$cmdsHtml  .= ' style="width:15px;max-width:15px;max-height:15px;">' . $icon . '</span>' . "\n\t\t";
				$cmdsHtml  .= '<span class="nut-label">' . $nameEnc . ' : </span>';
				$cmdsHtml  .= '<span data-cmd_id="' . $cmdId . '" class="' . $historized . '">' . $value . '</span>';
				if ($unite !== '') {
					$cmdsHtml .= ' ' . $unite;
				}
				$cmdsHtml .= "\n\t\t" . '</div>' . "\n\n\t\t";

			}
		}

		$replace['#cmds_html#'] = $cmdsHtml;
		$html = template_replace($replace, getTemplate('core', $_version, 'Nut_free', 'Nut_free'));
		return $html;
	}
	
    public function getInfosSSH(): void {
        if (!$this->getIsEnable()) return;

        $equipment      = $this->getName();
        $upsAutoSelect = $this->getConfiguration('upsAutoSelect', '0');
        $ups           = trim($this->getConfiguration('ups', ''));
        $sshHostId     = $this->getConfiguration('SSHHostId', '');

        log::add('Nut_free', 'debug', '--- [' . $equipment . '] Début collecte NUT via SSH ---');

        // --- Vérification SSH-Manager ---
        if (!class_exists('sshmanager')) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Plugin SSH-Manager introuvable - vérifiez les dépendances');
            return;
        }
        if (empty($sshHostId)) {
            log::add('Nut_free', 'error', '[' . $equipment . '] SSHHostId non configuré');
            return;
        }

        // --- Résolution du nom de l'UPS (auto-détection si upsAutoSelect = '0') ---
        if ($upsAutoSelect === '0' || $ups === '') {
            try {
                $upsListCmd = "upsc -l 2>&1 | grep -v '^Init SSL'";
                $ups = trim((string) sshmanager::executeCmds($sshHostId, $upsListCmd));
                log::add('Nut_free', 'debug', '[' . $equipment . '] UPS auto-détecté : ' . $ups);
            } catch (\Throwable $e) {
                log::add('Nut_free', 'error', '[' . $equipment . '] Auto-détection UPS échouée : ' . $e->getMessage());
                return;
            }
        } else {
            log::add('Nut_free', 'debug', '[' . $equipment . '] UPS manuel : ' . $ups);
        }

        if (empty($ups)) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Impossible de déterminer le nom de l\'UPS');
            return;
        }

        // --- Collecte unique de toutes les variables NUT (1 seul appel SSH) ---
        try {
            $allVarsRaw = (string) sshmanager::executeCmds(
                $sshHostId,
                'upsc ' . escapeshellarg($ups) . " 2>&1 | grep -v '^Init SSL'"
            );
        } catch (\Throwable $e) {
            log::add('Nut_free', 'error', '[' . $equipment . '] upsc erreur : ' . $e->getMessage());
            return;
        }

        // Parsing : "var.name: value" → ['var.name' => 'value', ...]
        $nutData = [];
        foreach (explode("\n", $allVarsRaw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) continue;
            [$varName, $varValue] = explode(':', $line, 2);
            $nutData[trim($varName)] = trim($varValue);
        }
        log::add('Nut_free', 'debug', '[' . $equipment . '] ' . count($nutData) . ' variable(s) reçues via upsc');

        // --- Distribution aux commandes Jeedom ---
        // Passe 1 : commandes directes (nutCmd défini) — construit aussi $logicalIdMap pour la passe 2
        // Passe 2 : commandes dérivées (derivedFrom défini) — calculées depuis les valeurs de la passe 1
        $logicalIdMap = []; // logicalId => valeur brute
        $derivedCmds  = []; // commandes dérivées, traitées après

        foreach ($this->getCmd() as $cmd) {
            if ($cmd->getType() === 'action') continue; // instcmds et actions RW : pas de lecture upsc

            $nutVar      = $cmd->getConfiguration('nutCmd', '');
            $derivedFrom = $cmd->getConfiguration('derivedFrom', '');

            // Commande dérivée : mise de côté pour la passe 2
            if (!empty($derivedFrom)) {
                $derivedCmds[] = $cmd;
                continue;
            }
            if (empty($nutVar)) continue; // commande sans var NUT (ex: cmd_result)

            if (!array_key_exists($nutVar, $nutData)) {
                // Variable non retournée par l'UPS : masquer la commande
                log::add('Nut_free', 'debug', '[' . $equipment . '] ' . $cmd->getName() . ' : non supporté par l\'UPS');
                $cmd->setIsVisible(0);
                $cmd->setEqLogic_id($this->getId());
                $cmd->save();
                continue;
            }

            $result = $nutData[$nutVar];
            $logicalIdMap[$cmd->getLogicalId()] = $result; // mémorise la valeur brute pour les dérivées

            // Conversion sec → min : toute commande avec unite='min' (-1 passé tel quel)
            if ($cmd->getUnite() === 'min' && trim((string) $result) !== '-1' && is_numeric($result)) {
                $result = (string) round((float) $result / 60, 2);
            }

            log::add('Nut_free', 'debug', '[' . $equipment . '] ' . $cmd->getName() . ' : ' . $result);
            $cmd->event($result);
        }

        // Passe 2 : commandes dérivées (ups_status_label, battery_runtime_min, _min dynamiques…)
        foreach ($derivedCmds as $cmd) {
            $derivedFrom = $cmd->getConfiguration('derivedFrom', '');
            if (!isset($logicalIdMap[$derivedFrom])) continue; // source non disponible

            $sourceValue = $logicalIdMap[$derivedFrom];
            $result      = $sourceValue;

            if ($cmd->getLogicalId() === 'ups_status_label') {
                $result = Nut_free::translateUpsStatus($sourceValue);
            } elseif ($cmd->getUnite() === 'min' && trim($sourceValue) !== '-1' && is_numeric($sourceValue)) {
                $result = (string) round((float) $sourceValue / 60, 2);
            }

            log::add('Nut_free', 'debug', '[' . $equipment . '] ' . $cmd->getName() . ' : ' . $result . ' (dérivé de ' . $derivedFrom . ')');
            $cmd->event($result);
        }

        log::add('Nut_free', 'debug', '--- [' . $equipment . '] Fin collecte NUT ---');
    }

    public static function deamon_info() {
        $return = array('log' => 'Nut_free_daemon', 'state' => 'nok', 'launchable' => 'ok');
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0 && @posix_getsid($pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pidFile);
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
        } catch (\Throwable $e) {
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
            . ' --socketport '    . (int) $socketPort
            . ' --callback '      . escapeshellarg($callbackUrl)
            . ' --apikey '        . escapeshellarg($apiKey)
            . ' --loglevel '      . escapeshellarg($logLevel)
            . ' --pluginversion ' . escapeshellarg(config::byKey('pluginVersion', 'Nut_free', '0.0.0'))
            . ' --cyclepolling '  . (float) $cyclePolling
            . ' --cyclewatcher '  . (float) $cycleWatcher
            . ' --cyclefactor '   . (float) $cycleFactor
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
                        'device' => self::buildDevicePayload($eqLogic),
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
            $pid = (int) trim((string) file_get_contents($pidFile));
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
        system::fuserk((int) config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT));
        log::add('Nut_free', 'info', '[DAEMON] Arrêté');
    }

    public static function sendToDaemon(array $params) {
        $params['apikey'] = jeedom::getApiKey('Nut_free');
        $payload = json_encode($params);
        if ($payload === false) {
            log::add('Nut_free', 'error', '[DAEMON] json_encode erreur : ' . json_last_error_msg());
            return false;
        }
        $socket  = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            log::add('Nut_free', 'error', '[DAEMON] socket_create erreur');
            return false;
        }
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 3, 'usec' => 0));
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 3, 'usec' => 0));
        if (!@socket_connect($socket, '127.0.0.1', (int) config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT))) {
            log::add('Nut_free', 'warning', '[DAEMON] socket_connect impossible (daemon arrêté ?)');
            socket_close($socket);
            return false;
        }
        $msg = $payload . "\n";
        if (socket_write($socket, $msg, strlen($msg)) === false) {
            log::add('Nut_free', 'warning', '[DAEMON] socket_write erreur : ' . socket_strerror(socket_last_error($socket)));
            socket_close($socket);
            return false;
        }
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

        // Commandes RW dynamiques : envoi SetRWVar au daemon (routage par nutRwVar configuré)
        $nutRwVar = $this->getConfiguration('nutRwVar', '');
        if (!empty($nutRwVar)) {
            $value = $_options['slider'] ?? $_options['message'] ?? '';
            log::add('Nut_free', 'info', '[' . $equipment . '] setrwvar : ' . $nutRwVar . ' = ' . $value);
            if ($connexionType === 'nut') {
                Nut_free::sendToDaemon(array(
                    'action'    => 'setrwvar',
                    'eqLogicId' => $eqLogic->getId(),
                    'nutRwVar'  => $nutRwVar,
                    'value'     => (string) $value,
                ));
            } else {
                throw new Exception(__('setrwvar non supporté en mode SSH (commande ' . $logicalId . ')', __FILE__));
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
                    throw new Exception(__('Plugin SSH-Manager introuvable - vérifiez les dépendances', __FILE__));
                }
                if (empty($sshHostId)) {
                    throw new Exception(__('SSHHostId non configuré pour l\'équipement ', __FILE__) . $equipment);
                }
                if (empty($ups)) {
                    throw new Exception(__('Nom UPS non configuré pour l\'équipement ', __FILE__) . $equipment);
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
        } catch (\Throwable $e) {
            log::add('Nut_free', 'error', '[' . $equipment . '] Erreur instcmd ' . $nutInstCmd . ' : ' . $e->getMessage());
            throw $e;
        }
    }
}
