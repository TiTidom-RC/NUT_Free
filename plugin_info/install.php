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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function Nut_free_install() {
    $pluginVersion = Nut_free::getPluginVersion();
    config::save('pluginVersion', $pluginVersion, 'Nut_free');

    $pluginBranch = Nut_free::getPluginBranch();
    config::save('pluginBranch', $pluginBranch, 'Nut_free');

    message::removeAll('Nut_free');
    message::add('Nut_free', 'Installation du plugin NUT Free (Version : ' . $pluginVersion . ')', null, null);

    Nut_free::getPythonDepFromRequirements();

    if (config::byKey('pythonVersion', 'Nut_free') == '') {
        config::save('pythonVersion', '?.?.?', 'Nut_free');
    }
    if (config::byKey('pyenvVersion', 'Nut_free') == '') {
        config::save('pyenvVersion', '?.?.?', 'Nut_free');
    }
    if (config::byKey('socketPort', 'Nut_free') == '') {
        config::save('socketPort', '55113', 'Nut_free');
    }
    if (config::byKey('cyclePolling', 'Nut_free') == '') {
        config::save('cyclePolling', '60', 'Nut_free');
    }
    if (config::byKey('cycleWatcher', 'Nut_free') == '') {
        config::save('cycleWatcher', '5', 'Nut_free');
    }
    if (config::byKey('cycleFactor', 'Nut_free') == '') {
        config::save('cycleFactor', '1.0', 'Nut_free');
    }
    if (config::byKey('debugInstallUpdates', 'Nut_free') == '') {
        config::save('debugInstallUpdates', '0', 'Nut_free');
    }
    if (config::byKey('debugRestorePyEnv', 'Nut_free') == '') {
        config::save('debugRestorePyEnv', '0', 'Nut_free');
    }
    if (config::byKey('debugRestoreVenv', 'Nut_free') == '') {
        config::save('debugRestoreVenv', '0', 'Nut_free');
    }
    if (config::byKey('disableUpdateMsg', 'Nut_free') == '') {
        config::save('disableUpdateMsg', '0', 'Nut_free');
    }

    // Enregistrement du cron de collecte (toutes les minutes)
    $cron = cron::byClassAndFunction('Nut_free', 'cron');
    if (!is_object($cron)) {
        $cron = new cron();
        $cron->setClass('Nut_free');
        $cron->setFunction('cron');
        $cron->setEnable(1);
        $cron->setDeamon(0);
        $cron->setSchedule('* * * * *');
        $cron->setTimeout(1);
        $cron->save();
    }

    $dependencyInfo = Nut_free::dependancy_info();
    if (!isset($dependencyInfo['state'])) {
        message::add('Nut_free', __('Veuillez vérifier les dépendances', __FILE__));
    } elseif ($dependencyInfo['state'] == 'nok') {
        try {
            $plugin = plugin::byId('Nut_free');
            $plugin->dependancy_install();
        } catch (\Throwable $th) {
            message::add('Nut_free', __('Une erreur est survenue à la mise à jour automatique des dépendances. Vérifiez les logs et relancez les dépendances manuellement', __FILE__));
        }
    }

    // Mise à jour des commandes de tous les équipements existants
    Nut_free::createCmd();
}

function Nut_free_update() {
    $pluginVersion = Nut_free::getPluginVersion();
    config::save('pluginVersion', $pluginVersion, 'Nut_free');

    $pluginBranch = Nut_free::getPluginBranch();
    config::save('pluginBranch', $pluginBranch, 'Nut_free');

    if (config::byKey('disableUpdateMsg', 'Nut_free', '0') == '0') {
        message::removeAll('Nut_free');
        message::add('Nut_free', 'Mise à jour du plugin NUT Free (Version : ' . $pluginVersion . ')', null, null);
    }

    Nut_free::getPythonDepFromRequirements();

    if (config::byKey('pythonVersion', 'Nut_free') == '') {
        config::save('pythonVersion', '?.?.?', 'Nut_free');
    }
    if (config::byKey('pyenvVersion', 'Nut_free') == '') {
        config::save('pyenvVersion', '?.?.?', 'Nut_free');
    }
    if (config::byKey('socketPort', 'Nut_free') == '') {
        config::save('socketPort', '55113', 'Nut_free');
    }
    if (config::byKey('cyclePolling', 'Nut_free') == '') {
        config::save('cyclePolling', '60', 'Nut_free');
    }
    if (config::byKey('cycleWatcher', 'Nut_free') == '') {
        config::save('cycleWatcher', '5', 'Nut_free');
    }
    if (config::byKey('cycleFactor', 'Nut_free') == '') {
        config::save('cycleFactor', '1.0', 'Nut_free');
    }
    if (config::byKey('debugInstallUpdates', 'Nut_free') == '') {
        config::save('debugInstallUpdates', '0', 'Nut_free');
    }
    if (config::byKey('debugRestorePyEnv', 'Nut_free') == '') {
        config::save('debugRestorePyEnv', '0', 'Nut_free');
    }
    if (config::byKey('debugRestoreVenv', 'Nut_free') == '') {
        config::save('debugRestoreVenv', '0', 'Nut_free');
    }
    if (config::byKey('disableUpdateMsg', 'Nut_free') == '') {
        config::save('disableUpdateMsg', '0', 'Nut_free');
    }

    // Nettoyage des fichiers et répertoires obsolètes des anciennes versions
    // À compléter au fur et à mesure des migrations
    $dirsToDelete = array(
        __DIR__ . '/../ressources',   // Ancienne orthographe → suppression pour les utilisateurs existants
    );

    $filesToDelete = array(
        __DIR__ . '/../plugin_info/packages.json',
    );

    try {
        foreach ($dirsToDelete as $dir) {
            log::add('Nut_free', 'debug', '[CLEAN] Vérification répertoire :: ' . $dir);
            if (file_exists($dir)) {
                $output = shell_exec('sudo rm -rf ' . escapeshellarg($dir) . ' 2>&1');
                if (file_exists($dir)) {
                    log::add('Nut_free', 'warning', '[CLEAN] Échec suppression répertoire :: ' . $dir . (!empty($output) ? ' :: ' . trim($output) : ''));
                } else {
                    log::add('Nut_free', 'debug', '[CLEAN] Supprimé :: ' . $dir);
                }
            } else {
                log::add('Nut_free', 'debug', '[CLEAN] Absent (OK) :: ' . $dir);
            }
        }

        foreach ($filesToDelete as $file) {
            log::add('Nut_free', 'debug', '[CLEAN] Vérification fichier :: ' . $file);
            if (file_exists($file)) {
                $output = shell_exec('sudo rm -f ' . escapeshellarg($file) . ' 2>&1');
                if (file_exists($file)) {
                    log::add('Nut_free', 'warning', '[CLEAN] Échec suppression fichier :: ' . $file . (!empty($output) ? ' :: ' . trim($output) : ''));
                } else {
                    log::add('Nut_free', 'debug', '[CLEAN] Supprimé :: ' . $file);
                }
            } else {
                log::add('Nut_free', 'debug', '[CLEAN] Absent (OK) :: ' . $file);
            }
        }
    } catch (Exception $e) {
        log::add('Nut_free', 'warning', '[CLEAN] Exception :: ' . $e->getMessage());
    }

    // S'assurer que le cron existe toujours après une mise à jour
    $cron = cron::byClassAndFunction('Nut_free', 'cron');
    if (!is_object($cron)) {
        $cron = new cron();
        $cron->setClass('Nut_free');
        $cron->setFunction('cron');
        $cron->setEnable(1);
        $cron->setDeamon(0);
        $cron->setSchedule('* * * * *');
        $cron->setTimeout(1);
        $cron->save();
    }

    // Mise à jour des commandes de tous les équipements existants (propage nutCmd, unité, etc.)
    Nut_free::createCmd();
}

function Nut_free_remove() {
    // Suppression du cron à la désinstallation du plugin
    $cron = cron::byClassAndFunction('Nut_free', 'cron');
    if (is_object($cron)) {
        $cron->remove();
    }
}
