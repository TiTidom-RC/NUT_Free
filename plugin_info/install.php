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

/**
 * Initialise les clés de configuration par défaut si elles sont absentes.
 * Appelé à l'installation et à la mise à jour.
 */
function initDefaultConfig(): void {
    $defaults = array(
        'pythonVersion'       => '?.?.?',
        'pyenvVersion'        => '?.?.?',
        'socketPort'          => '55113',
        'cyclePolling'        => '60',
        'cycleWatcher'        => '5',
        'cycleFactor'         => '1.0',
        'sshRandomDelay'      => '15',
        'debugInstallUpdates' => '0',
        'debugRestorePyEnv'   => '0',
        'debugRestoreVenv'    => '0',
        'disableUpdateMsg'    => '0',
    );
    foreach ($defaults as $key => $value) {
        if (config::byKey($key, 'Nut_free') == '') {
            config::save($key, $value, 'Nut_free');
        }
    }
}

function Nut_free_install() {
    $pluginVersion = Nut_free::getPluginVersion();
    config::save('pluginVersion', $pluginVersion, 'Nut_free');

    $pluginBranch = Nut_free::getPluginBranch();
    config::save('pluginBranch', $pluginBranch, 'Nut_free');

    message::removeAll('Nut_free');
    message::add('Nut_free', 'Installation du plugin NUT Free (Version : ' . $pluginVersion . ')', null, null);

    Nut_free::getPythonDepFromRequirements();
    initDefaultConfig();

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
    initDefaultConfig();

    // Nettoyage des fichiers et répertoires obsolètes des anciennes versions
    // À compléter au fur et à mesure des migrations
    $dirsToDelete = array(
        __DIR__ . '/../ressources',   // Ancienne orthographe → suppression pour les utilisateurs existants
        __DIR__ . '/../docs',         // Documentation centralisée dans le projet Documentation
    );

    $filesToDelete = array(
        __DIR__ . '/packages.json',                  // remplacé par info.json
        __DIR__ . '/../resources/install.sh',        // remplacé par install_apt.sh
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
                if (@unlink($file)) {
                    log::add('Nut_free', 'debug', '[CLEAN] Supprimé :: ' . $file);
                } else {
                    log::add('Nut_free', 'warning', '[CLEAN] Échec suppression fichier :: ' . $file);
                }
            } else {
                log::add('Nut_free', 'debug', '[CLEAN] Absent (OK) :: ' . $file);
            }
        }
    } catch (\Throwable $e) {
        log::add('Nut_free', 'warning', '[CLEAN] Exception :: ' . $e->getMessage());
    }

    // Migration : supprimer le cron manuel créé par les anciennes versions du plugin.
    // Jeedom appelle nativement Nut_free::cron() via plugin::cron() — un cron explicite créerait une double exécution.
    $oldCron = cron::byClassAndFunction('Nut_free', 'cron');
    if (is_object($oldCron)) {
        $oldCron->remove();
        log::add('Nut_free', 'info', '[UPDATE] Cron manuel supprimé (géré nativement par Jeedom)');
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
