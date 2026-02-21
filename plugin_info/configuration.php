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
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}

$_versionSSHManager = config::byKey('pluginVersion', 'sshmanager', 'N/A');
$_branchSSHManager  = config::byKey('pluginBranch',  'sshmanager', 'N/A');
$_branchNutFree     = config::byKey('pluginBranch',  'Nut_free',   'N/A');

function _branchLabel(string $branch): string {
    if (strpos($branch, 'stable') !== false) {
        return '<span class="label label-success text-capitalize">' . $branch . '</span>';
    }
    if (strpos($branch, 'beta') !== false) {
        return '<span class="label label-warning text-capitalize">' . $branch . '</span>';
    }
    if (strpos($branch, 'dev') !== false) {
        return '<span class="label label-danger text-capitalize">' . $branch . '</span>';
    }
    return '<span class="label label-info">N/A</span>';
}

$_labelBranchNut  = _branchLabel($_branchNutFree);
$_labelBranchSSHM = _branchLabel($_branchSSHManager);
?>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-info-circle"></i> {{Plugin(s)}}</legend>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Version NUT Free}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Version du plugin NUT Free à indiquer sur Community}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="pluginVersion" readonly />
            </div>
            <div class="col-md-2">
                <?php echo $_labelBranchNut ?>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Version SSH-Manager}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Version du plugin SSH-Manager à indiquer sur Community}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="form-control" value="<?php echo htmlspecialchars($_versionSSHManager) ?>" readonly />
            </div>
            <div class="col-md-2">
                <?php echo $_labelBranchSSHM ?>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Version Python}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Version de Python utilisée par le plugin (à indiquer sur Community)}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="pythonVersion" readonly />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Version PyEnv}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Version de PyEnv utilisée par le plugin (à indiquer sur Community)}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="pyenvVersion" readonly />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Désactiver les messages de MàJ}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Cocher cette case désactivera les messages de mise à jour du plugin dans le centre de message}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input type="checkbox" class="configKey" data-l1key="disableUpdateMsg" />
            </div>
        </div>

        <legend><i class="fas fa-code"></i> {{Dépendances}}</legend>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Force les mises à jour Systèmes}}
                <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer l'installation des mises à jour systèmes}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input type="checkbox" class="configKey" data-l1key="debugInstallUpdates" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Force la réinitialisation de PyEnv}}
                <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer la réinitialisation de l'environnement Python utilisé par le plugin}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input type="checkbox" class="configKey" data-l1key="debugRestorePyEnv" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Force la réinitialisation de Venv}}
                <sup><i class="fas fa-ban tooltips" style="color:var(--al-danger-color)!important;" title="{{Les dépendances devront être relancées après la sauvegarde de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{Permet de forcer la réinitialisation de l'environnement Venv utilisé par le plugin}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input type="checkbox" class="configKey" data-l1key="debugRestoreVenv" />
            </div>
        </div>

        <legend><i class="fas fa-university"></i> {{Démon}}</legend>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Port Socket Interne}}
                <sup><i class="fas fa-exclamation-triangle tooltips" style="color:var(--al-warning-color)!important;" title="{{Le démon devra être redémarré après la modification de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{[ATTENTION] Ne changez ce paramètre qu'en cas de nécessité. (Défaut = 55113)}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="socketPort" placeholder="55113" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Intervalle de polling (secondes)}}
                <sup><i class="fas fa-exclamation-triangle tooltips" style="color:var(--al-warning-color)!important;" title="{{Le démon devra être redémarré après la modification de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{Intervalle en secondes entre chaque interrogation des UPS (Défaut = 60)}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="cyclePolling" placeholder="60" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-3 control-label">{{Facteur de cycle interne}}
                <sup><i class="fas fa-exclamation-triangle tooltips" style="color:var(--al-warning-color)!important;" title="{{Le démon devra être redémarré après la modification de ce paramètre}}"></i></sup>
                <sup><i class="fas fa-question-circle tooltips" title="{{Facteur multiplicateur appliqué aux cycles internes du démon (Main/Comm/Event). Défaut = 1.0. Diminuer pour accélérer la réactivité}}"></i></sup>
            </label>
            <div class="col-md-1">
                <input class="configKey form-control" data-l1key="cycleFactor" placeholder="1.0" />
            </div>
        </div>
    </fieldset>
</form>
