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
 * Callback HTTP reçu par le daemon Python nutfreed.
 * Format attendu (POST JSON) :
 * {
 *   "apikey": "...",
 *   "update": {
 *     "<eqLogic_id>": {
 *       "<logicalId>": "<value>",
 *       ...
 *     },
 *     ...
 *   }
 * }
 */

try {
    require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

    // ----- Vérification API key -----
    $apikey = init('apikey');
    if (empty($apikey)) {
        $body = file_get_contents('php://input');
        if (!empty($body)) {
            $payload = json_decode($body, true);
            if (isset($payload['apikey'])) {
                $apikey = $payload['apikey'];
            }
        }
    }

    if (!jeedom::apiAccess($apikey, 'Nut_free')) {
        log::add('Nut_free', 'error', '[jeeNut_free] Accès refusé : clé API invalide');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }

    // ----- Lecture du corps POST -----
    $body = file_get_contents('php://input');
    if (empty($body)) {
        log::add('Nut_free', 'warning', '[jeeNut_free] Corps POST vide');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        log::add('Nut_free', 'error', '[jeeNut_free] JSON invalide :: ' . $body);
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }

    // ----- Traitement des mises à jour -----
    if (!isset($data['update']) || !is_array($data['update'])) {
        log::add('Nut_free', 'debug', '[jeeNut_free] Aucune clé "update" dans le payload');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    foreach ($data['update'] as $eqLogic_id => $values) {
        if (!is_array($values)) {
            continue;
        }

        /** @var Nut_free $eqLogic */
        $eqLogic = Nut_free::byId($eqLogic_id);
        if (!is_object($eqLogic)) {
            log::add('Nut_free', 'warning', '[jeeNut_free] eqLogic introuvable : ' . $eqLogic_id);
            continue;
        }

        if (!$eqLogic->getIsEnable()) {
            log::add('Nut_free', 'debug', '[jeeNut_free] eqLogic désactivé, ignore : ' . $eqLogic_id);
            continue;
        }

        $updated = false;
        foreach ($values as $logicalId => $value) {
            $cmd = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                log::add('Nut_free', 'debug', '[jeeNut_free][' . $eqLogic_id . '] Commande introuvable : ' . $logicalId);
                continue;
            }
            $cmd->event($value);
            $updated = true;
            log::add('Nut_free', 'debug', '[jeeNut_free][' . $eqLogic_id . '] ' . $logicalId . ' = ' . $value);
        }

        if ($updated) {
            $eqLogic->refreshWidget();
            log::add('Nut_free', 'info', '[jeeNut_free] Widget rafraîchi pour eqLogic ' . $eqLogic_id);
        }
    }

    echo json_encode(['status' => 'ok']);

} catch (Exception $e) {
    log::add('Nut_free', 'error', '[jeeNut_free] Exception :: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
