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

/**
 * Point d'entrée AJAX pour le plugin NUT Free.
 * Requiert une session admin active (authentification par cookie de session Jeedom).
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init(array());

    // ----- Action : Synchroniser toutes les commandes dynamiques avec l'onduleur -----
    if (init('action') == 'discoverAll') {
        $eqLogicId = init('eqLogicId');

        if (empty($eqLogicId)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        /** @var Nut_free $eqLogic */
        $eqLogic = Nut_free::byId($eqLogicId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($eqLogic->getConfiguration('connexionMode', 'nut') !== 'nut') {
            throw new Exception(__('Fonctionnalité disponible uniquement en mode NUT direct', __FILE__));
        }

        $eqLogic->setConfiguration('discover_status', 'pending');
        $eqLogic->setConfiguration('discover_error', '');
        $eqLogic->save();

        $sent = Nut_free::sendToDaemon(array(
            'action'    => 'discover_all',
            'eqLogicId' => $eqLogicId,
        ));

        if (!$sent) {
            throw new Exception(__('Impossible de contacter le démon (vérifiez qu\'il est démarré)', __FILE__));
        }

        ajax::success(__('ok', __FILE__));
    }

    // ----- Action : Supprimer toutes les commandes dynamiques d'un équipement -----
    if (init('action') == 'cleanDynamicCmds') {
        $eqLogicId = init('eqLogicId');

        if (empty($eqLogicId)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        /** @var Nut_free $eqLogic */
        $eqLogic = Nut_free::byId($eqLogicId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        $eqLogic->cleanDynamicCmds();

        ajax::success(__('ok', __FILE__));
    }

    throw new Exception(__('Action inconnue : ' . init('action'), __FILE__));

} catch (\Throwable $e) {
    ajax::error($e->getMessage(), $e->getCode());
}
