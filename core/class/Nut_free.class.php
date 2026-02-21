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

	public static $_infosMap = array(
		//on crée un tableau contenant la liste des infos a traiter 
		//chaque info a un sous tableau avec les paramètres 
		// dans postSave() il faut le parcourir pour créer les cmd
		// 		array(
		// 			'name' =>'Nom de l\'équipement info'
		// 			'logicalId'=>'Id de l\'équipement',
		// 			'type'=>'info', //on peut ne pas spécifier cette valeur et alors dans la boucle mettre celle par défaut
		// 			'subType'=>'string', //idem
		// 			'order' => 1, // ici on pourrait utiliser l'index du tableau et l'ordre serait le même que ce tableau
		// 			'template_dashboard'=> 'line'
		//			'cmd' => 'ups.status', //commande à exécuter
		// 		),
	
		array(
			'name' =>'Marque_Model',
			'logicalId'=>'Marque',
			'template_dashboard'=> 'line',
			'subtype'=>'string',
			'cmd'=>'device.mfr',
		),
		array(
			'name' =>'Model',
			'logicalId'=>'Model',
			'subtype'=>'string',
			'cmd'=>'device.model',
		),
		array(
			'name' =>'Serial',
			'logicalId'=>'ups_serial',
			'cmd' => 'ups.serial',
			'subtype'=>'string',
		),
		array(
			'name' =>'UPS MODE',
			'logicalId'=>'ups_line',
			'cmd' => 'ups.status',
			'subtype'=>'string',
		),
		array(
			'name' =>'Tension en entrée',
			'logicalId'=>'input_volt',
			'cmd'=>'input.voltage',
			'unite'=>'V',
		),
		array(
			'name' =>'Fréquence en entrée',
			'logicalId'=>'input_freq',
			'cmd'=>'input.frequency',
			'unite'=>'Hz',
		),
		array(
			'name' =>'Tension en sortie',
			'logicalId'=>'output_volt',
			'cmd'=>'output.voltage',
			'unite'=>'V',
		),
		array(
			'name' =>'Fréquence en sortie',
			'logicalId'=>'output_freq',
			'cmd'=>'output.frequency',
			'unite'=>'Hz',
		),
		array(
			'name' =>'Puissance en sortie',
			'logicalId'=>'output_power',
			'cmd'=>'ups.power',
			'unite'=>'VA',
		),
		array(
			'name' =>'Puissance en sortie réel',
			'logicalId'=>'output_real_power',
			'cmd'=>'ups.realpower',
			'unite'=>'W',
		),
		array(
			'name' =>'Niveau de charge batterie',
			'logicalId'=>'batt_charge',
			'cmd'=>'battery.charge',
			'unite'=>'%',
		),
		array(
			'name' =>'Tension de la batterie',
			'logicalId'=>'batt_volt',
			'cmd'=>'battery.voltage',
			'unite'=>'V',
		),
		array(
		  	'name'      => 'Température de la batterie',
		  	'logicalId' => 'batt_temp',
		  	'cmd'       => 'battery.temperature',
		  	'unite'     => '°C',
		),
		array(
			'name'      => 'Température ups',
			'logicalId' => 'ups_temp',
			'cmd'       => 'ups.temperature',
			'unite'     => '°C',
	  ),
		array(
			'name' =>'Charge onduleur',
			'logicalId'=>'ups_load',
			'cmd'=>'ups.load',
			'unite'=>'%',
		),
		array(
			'name' =>'Temps restant sur batterie en s',
			'logicalId'=>'batt_runtime',
			'cmd'=>'battery.runtime',
			'unite'=>'s',
		),
     		 array(
			'name' =>'Temps restant sur batterie en min',
			'logicalId'=>'batt_runtime_min',
			'cmd'=>'battery.runtime',
			'unite'=>'min',
		),
		array(
			'name' =>'Temps restant avant arrêt en s',
			'logicalId'=>'timer_shutdown',
			'cmd'=>'ups.timer.shutdown',
			'unite'=>'s',
		),
      		array(
			'name' =>'Temps restant avant arrêt en min',
			'logicalId'=>'timer_shutdown_min',
			'cmd'=>'ups.timer.shutdown',
			'unite'=>'min',
		),
		array(
			'name' =>'Beeper',
			'logicalId'=>'beeper_stat',
			'subtype'=>'string',
			'cmd'=>'ups.beeper.status',
		),

	);

    public static function cron() {
        foreach (eqLogic::byType('Nut_free') as $Nut_free) {
            /** @var Nut_free $Nut_free */
            if (!$Nut_free->getIsEnable()) continue;
            $mode = $Nut_free->getConfiguration('localoudistant', 'local');
            if ($mode === 'local') {
                // Mode local : le daemon Python gère le polling automatiquement.
                // On s'assure simplement que l'équipement est enregistré dans le daemon.
                self::sendToDaemon(array(
                    'action'      => 'add_device',
                    'device'      => array(
                        'eqLogic_id'  => $Nut_free->getId(),
                        'host'        => $Nut_free->getConfiguration('addressip', '127.0.0.1'),
                        'port'        => (int) $Nut_free->getConfiguration('nut_port', 3493),
                        'ups_name'    => $Nut_free->getConfiguration('UPS', ''),
                        'auto_detect' => ($Nut_free->getConfiguration('UPS_auto_select', '0') === '0') ? 1 : 0,
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

	public static function dependancy_install() {
		log::remove(__CLASS__ . '_update');

		$script_restoreVenv = 0;
		if (config::byKey('debugRestoreVenv', 'Nut_free') == '1') {
			$script_restoreVenv = 1;
			config::save('debugRestoreVenv', '0', 'Nut_free');
		}

		return array(
			'script' => __DIR__ . '/../../resources/install.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency ' . $script_restoreVenv,
			'log'    => log::getPathToLog(__CLASS__ . '_update'),
		);
	}

	public function postSave() {
				
		$idx = 0;
		// parcours du tableau $idx contient l'index commençant a 0 et $info contient le sous tableau de  paramètres l'info
		foreach(self::$_infosMap as $idx=>$info)
		{
			$Nut_freeCmd = $this->getCmd(null, $info['logicalId']); // on récupère nos valeurs
			if (!is_object($Nut_freeCmd)) {
				$Nut_freeCmd = new Nut_freeCmd();
				$Nut_freeCmd->setLogicalId( $info['logicalId']);
				$Nut_freeCmd->setName(__( $info['name'], __FILE__));
					if(isset($info['unite'])){
						$Nut_freeCmd->setUnite($info['unite']);
				}
				$Nut_freeCmd->setOrder($idx+1); //+1 car $idx commence a 0
					
				if(isset($info['template_dashboard'])) //on vérifie si on a spécifié un template, si oui on l'affecte, on peu créer une autre clé $info['template_mobile'] si besoin
					$Nut_freeCmd->setTemplate('dashboard', $info['template_dashboard']);
			}
			
			$Nut_freeCmd->setType($info['type'] ?? 'info');
			
			//$Nut_freeCmd->setSubType($params['subtype'] ?: 'string');
			if (isset($info['subtype'])) {
				$Nut_freeCmd->setSubType($info['subtype']);
			} else {
				$Nut_freeCmd->setSubType('numeric');
			}
			
			if (isset($info['isVisible'])) {
				$Nut_freeCmd->setIsVisible($info['isVisible']);
			}
			$Nut_freeCmd->setEqLogic_id($this->getId());
			// sur le même modèle tu peux ajouter d'autres paramètres qu'il faudrait changer par info
			
			$Nut_freeCmd->save();
		}

		// Déclencher une première collecte selon le mode de connexion
		$mode = $this->getConfiguration('localoudistant', 'local');
		if ($mode === 'local') {
			self::sendToDaemon(array(
				'action' => 'add_device',
				'device' => array(
					'eqLogic_id'  => $this->getId(),
					'host'        => $this->getConfiguration('addressip', '127.0.0.1'),
					'port'        => (int) $this->getConfiguration('nut_port', 3493),
					'ups_name'    => $this->getConfiguration('UPS', ''),
					'auto_detect' => ($this->getConfiguration('UPS_auto_select', '0') === '0') ? 1 : 0,
				),
			));
		} else {
			$this->getInfosSSH();
		}
	}
	
 	public function toHtml($_version = 'dashboard')	{
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$_version = jeedom::versionAlias($_version);
		$cmd_html = '';
		$br_before = 0;
		
		foreach(self::$_infosMap as $idx=>$info)
		{
			$cmd = $this->getCmd(null,$info['logicalId']);
			$replace['#'.$info['logicalId'].'#'] = (is_object($cmd)) ? $cmd->execCmd() : '';
			$replace['#'.$info['logicalId'].'id#'] = is_object($cmd) ? $cmd->getId() : '';
			$replace['#'.$info['logicalId'].'_display#'] = (is_object($cmd) && $cmd->getIsVisible()) ? '#'.$info['logicalId'].'_display#' : "none";
		}
		////////////////////////////////////////////////////////////////////
		foreach ($this->getCmd(null, null, true) as $cmd) {
			if (isset($replace['#refresh_id#']) && $cmd->getId() == $replace['#refresh_id#']) {
				continue;
			}
			if ($br_before == 0 && $cmd->getDisplay('forceReturnLineBefore', 0) == 1) {
				$cmd_html .= '<br/>';
			}
			if (isset($replace['#background-color#'])) {
			$cmd_html .= $cmd->toHtml($_version, '', $replace['#background-color#']);
			}
			$br_before = 0;
			if ($cmd->getDisplay('forceReturnLineAfter', 0) == 1) {
				$cmd_html .= '<br/>';
				$br_before = 1;
			}
		}
		
		
		///////////////////////////////////////////////////////////////////
		/*
		/////////////////////////////////////////////////////////////
		//('action')
		foreach ($this->getCmd('action') as $cmd) {
			$replace['#cmd_' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
		}
		////////////////////////////////////////////////////////////////
		*/
		$html = template_replace($replace, getTemplate('core', $_version, 'Nut_free','Nut_free'));
		//cache::set('Nut_freeWidget' . $_version . $this->getId(), $html, 0);
		
		return $html;
	}
	/*
	public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$_version = jeedom::versionAlias($_version);
		$cmd_html = '';
		$br_before = 0;
		foreach ($this->getCmd(null, null, true) as $cmd) {
			if (isset($replace['#refresh_id#']) && $cmd->getId() == $replace['#refresh_id#']) {
				continue;
			}
			if ($br_before == 0 && $cmd->getDisplay('forceReturnLineBefore', 0) == 1) {
				$cmd_html .= '<br/>';
			}
			$cmd_html .= $cmd->toHtml($_version, '', $replace['#cmd-background-color#']);
			$br_before = 0;
			if ($cmd->getDisplay('forceReturnLineAfter', 0) == 1) {
				$cmd_html .= '<br/>';
				$br_before = 1;
			}
		}
		$replace['#cmd#'] = $cmd_html;
		return template_replace($replace, getTemplate('core', $_version, 'worxLandroid', 'worxLandroid'));
	}
	*/
    public function getInfosSSH() {
        if (!$this->getIsEnable()) return;

        $equipement      = $this->getName();
        $UPS_auto_select = $this->getConfiguration('UPS_auto_select', '0');
        $ups             = trim($this->getConfiguration('UPS', ''));
        $sshHostId       = $this->getConfiguration('SSHHostId', '');

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

        // --- Résolution du nom de l'UPS (auto-détection si UPS_auto_select = 0) ---
        if ($UPS_auto_select === '0' || $ups === '') {
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

        foreach (self::$_infosMap as $idx => $info) {
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
            if ($idx === 0) {
                $Marque = $result;
            }
            if ($idx === 1) {
                $result = trim($Marque . ' ' . $result);
            }

            // Mode ligne / batterie
            if ($info['logicalId'] === 'ups_line') {
                $Not_Online = (stripos($result, 'OL') === false) ? 1 : 0;
                log::add('Nut_free', 'debug', '[' . $equipement . '] ups_line Not_Online=' . $Not_Online . ' result=' . $result);
            }

            // Tension entrée forcée à 0 quand sur batterie
            if ($info['logicalId'] === 'input_volt' && $Not_Online === 1) {
                $result = 0;
                log::add('Nut_free', 'debug', '[' . $equipement . '] input_volt forcé à 0 (mode batterie)');
            }

            // Conversion secondes → minutes
            if ($info['logicalId'] === 'batt_runtime_min' || $info['logicalId'] === 'timer_shutdown_min') {
                $result = (int) ((float) $result / 60);
            }

            if ($errorresult !== '') {
                log::add('Nut_free', 'debug', '[' . $equipement . '] ' . $info['name'] . ' : non supporté par l\'UPS');
                $cmd = $this->getCmd(null, $info['logicalId']);
                if (is_object($cmd)) {
                    $cmd->setIsVisible(0);
                    $cmd->setEqLogic_id($this->getId());
                    $cmd->save();
                }
            } else {
                log::add('Nut_free', 'debug', '[' . $equipement . '] ' . $info['name'] . ' : ' . $result);
                $cmd = $this->getCmd(null, $info['logicalId']);
                if (is_object($cmd)) {
                    $cmd->event($result);
                }
            }
        }

        log::add('Nut_free', 'debug', '--- [' . $equipement . '] Fin collecte NUT ---');
    }

    // =========================================================================
    // Méthodes daemon Python (mode local)
    // =========================================================================

    public static function deamon_info() {
        $return = array('log' => 'Nut_free_daemon', 'state' => 'nok', 'launchable' => 'ok');
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (!file_exists($pidFile)) {
            $return['state'] = 'nok';
            return $return;
        }
        $pid = intval(trim(file_get_contents($pidFile)));
        if ($pid <= 0 || !posix_getsid($pid)) {
            $return['state'] = 'nok';
            return $return;
        }
        $return['state'] = 'ok';
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
        } catch (Exception $e) {
            log::add('Nut_free', 'error', '[DAEMON][START][PythonDep] Exception :: ' . $e->getMessage());
        }

        $python3       = realpath(self::VENV_PYTHON);
        $script        = realpath(self::DAEMON_SCRIPT);
        $pidFile       = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        $logLevel      = log::convertLogLevel(log::getLogLevel('Nut_free')) ?: 'error';
        $apiKey        = jeedom::getApiKey('Nut_free');
        $socketPort    = config::byKey('socketPort', 'Nut_free', self::DAEMON_PORT);
        $callbackUrl   = network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/Nut_free/core/php/jeeNut_free.php';
        $cyclePolling  = config::byKey('cyclePolling', 'Nut_free', 60);
        $cycleFactor   = config::byKey('cycleFactor', 'Nut_free', '1.0');

        if (empty($python3) || !file_exists($python3)) {
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
                if ($eqLogic->getIsEnable() && $eqLogic->getConfiguration('localoudistant', 'local') === 'local') {
                    self::sendToDaemon(array(
                        'action' => 'add_device',
                        'device' => array(
                            'eqLogic_id'  => $eqLogic->getId(),
                            'host'        => $eqLogic->getConfiguration('addressip', '127.0.0.1'),
                            'port'        => (int) $eqLogic->getConfiguration('nut_port', 3493),
                            'ups_name'    => $eqLogic->getConfiguration('UPS', ''),
                            'auto_detect' => ($eqLogic->getConfiguration('UPS_auto_select', '0') === '0') ? 1 : 0,
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
                posix_kill($pid, SIGTERM);
                sleep(1);
                if (posix_getsid($pid)) {
                    posix_kill($pid, SIGKILL);
                }
            }
            unlink($pidFile);
        }
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
        $connexionType  = $eqLogic->getConfiguration('localoudistant', 'local');
        $ip             = $eqLogic->getConfiguration('addressip', '');
        $ups            = trim($eqLogic->getConfiguration('UPS', ''));
        $sshHostId      = $eqLogic->getConfiguration('SSHHostId', '');
        $logicalId      = $this->getLogicalId();
        $equipement     = $eqLogic->getName();

        // Construire la commande NUT upscmd
        $nutCmd = 'upscmd ' . escapeshellarg($ups) . ' ' . escapeshellarg($logicalId);

        log::add('Nut_free', 'info', '[' . $equipement . '] Action : ' . $nutCmd . ' (mode=' . $connexionType . ')');

        try {
            if ($connexionType === 'local') {
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
