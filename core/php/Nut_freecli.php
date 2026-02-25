<?php

/** @entrypoint */
/** @console */

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

require_once __DIR__ . '/../../../../core/php/console.php';
require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!isset($argv[1])) {
    $argv[1] = '';
}
if (!isset($argv[2])) {
    $argv[2] = 'Nut_free_update';
}

$_logName = $argv[2];

switch ($argv[1]) {
    case 'depinstall':
        try {
            $_plugin = plugin::byId('sshmanager');
            log::add($_logName, 'info', '[DEP-INSTALL] Le plugin SSH-Manager est déjà installé');
            if (!$_plugin->isActive()) {
                log::add($_logName, 'error', '[DEP-INSTALL] Le plugin SSH-Manager n\'est pas activé');
                $_plugin->setIsEnable(1, true, true);
                log::add($_logName, 'info', '[DEP-INSTALL] Activation du plugin SSH-Manager');
            } else {
                log::add($_logName, 'info', '[DEP-INSTALL] Plugin SSH-Manager :: actif');
            }
        } catch (Exception $e) {
            log::add($_logName, 'warning', '[DEP-INSTALL] ' . $e->getMessage());
            log::add($_logName, 'info', '[DEP-INSTALL] Lancement de l\'installation du plugin SSH-Manager');

            // Installation de SSH-Manager depuis la même source que NUT Free
            $_pluginSource    = update::byLogicalId('Nut_free');
            $_pluginToInstall = update::byLogicalId('sshmanager');
            if (!is_object($_pluginToInstall)) {
                $_pluginToInstall = new update();
            }
            $_pluginToInstall->setLogicalId('sshmanager');
            $_pluginToInstall->setType('plugin');
            $_pluginToInstall->setSource($_pluginSource->getSource());

            if ($_pluginSource->getSource() == 'github') {
                $_pluginToInstall->setConfiguration('user', $_pluginSource->getConfiguration('user'));
                $_pluginToInstall->setConfiguration('repository', 'SSH-Manager');
                if (strpos($_pluginSource->getConfiguration('version', 'stable'), 'dev') !== false) {
                    $_pluginToInstall->setConfiguration('version', 'dev');
                    log::add($_logName, 'info', '[DEP-INSTALL] Installation de la version :: dev (GitHub)');
                } else {
                    $_pluginToInstall->setConfiguration('version', $_pluginSource->getConfiguration('version', 'stable'));
                    log::add($_logName, 'info', '[DEP-INSTALL] Installation de la version :: ' . $_pluginSource->getConfiguration('version', 'stable') . ' (GitHub)');
                }
                $_pluginToInstall->setConfiguration('token', $_pluginSource->getConfiguration('token'));
            } else {
                $_pluginToInstall->setConfiguration('version', $_pluginSource->getConfiguration('version', 'stable'));
                log::add($_logName, 'info', '[DEP-INSTALL] Installation de la version :: ' . $_pluginSource->getConfiguration('version', 'stable') . ' (Market)');
            }
            $_pluginToInstall->save();
            $_pluginToInstall->doUpdate();

            // Attente de confirmation d'installation (max 30 secondes)
            $isNotInstalled = true;
            $num = 30;
            $_plugin = null;
            while ($isNotInstalled && $num > 0) {
                try {
                    $_plugin = plugin::byId('sshmanager');
                    $isNotInstalled = false;
                } catch (Exception $e) {
                    log::add($_logName, 'debug', '[DEP-INSTALL] Attente (' . strval($num) . ') :: ' . $e->getMessage());
                    $num--;
                    sleep(1);
                }
            }

            if ($num == 0) {
                log::add($_logName, 'error', '[DEP-INSTALL] Le plugin SSH-Manager n\'a pas pu être installé !');
            } else {
                log::add($_logName, 'info', '[DEP-INSTALL] Le plugin SSH-Manager est maintenant installé');
                if (is_object($_plugin)) {
                    try {
                        $_plugin->setIsEnable(1, true, true);
                        log::add($_logName, 'info', '[DEP-INSTALL] Le plugin SSH-Manager est maintenant activé');
                        jeedom::cleanFileSystemRight();
                    } catch (Exception $e) {
                        log::add($_logName, 'warning', '[DEP-INSTALL] Exception :: ' . $e->getMessage());
                        log::add($_logName, 'error', '[DEP-INSTALL] Le plugin SSH-Manager n\'a pas pu être activé !');
                    }
                }
            }
        }
        log::add($_logName, 'info', '[DEP-INSTALL] >>>> Fin des dépendances <<<<');
        break;

    default:
        help();
        break;
}

function help() {
    echo "Usage:  Nut_freecli.php [OPTIONS] COMMAND\n\n";
    echo "Nut_freecli permet d'effectuer des actions sur le plugin en ligne de commande\n\n";
    echo "Commands :\n";
    echo "\t depinstall : installe le plugin SSH-Manager (optionnel, mode distant uniquement)\n";
}
