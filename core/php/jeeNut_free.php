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
 *     "<eqLogicId>": {
 *       "<logicalId>": "<value>",
 *       ...
 *     },
 *     ...
 *   }
 * }
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

    // ----- Lecture du corps POST (une seule fois) -----
    $body = file_get_contents('php://input');
    if (empty($body)) {
        log::add('Nut_free', 'warning', '[CALLBACK] Corps POST vide');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        log::add('Nut_free', 'error', '[CALLBACK] JSON invalide :: ' . $body);
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }

    // ----- Vérification API key -----
    $apikey = init('apikey');
    if (empty($apikey) && isset($data['apikey'])) {
        $apikey = $data['apikey'];
    }

    if (!jeedom::apiAccess($apikey, 'Nut_free')) {
        log::add('Nut_free', 'error', '[CALLBACK] Accès refusé : clé API invalide');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }

    // ----- Traitement des événements daemon -----
    if (isset($data['daemonStarted'])) {
        if ($data['daemonStarted'] === '1') {
            log::add('Nut_free', 'info', '[CALLBACK] Démon démarré');
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if (isset($data['heartbeat'])) {
        if ($data['heartbeat'] === '1') {
            log::add('Nut_free', 'info', '[CALLBACK] Démon :: Heartbeat (' . config::byKey('heartbeatFrequency', 'Nut_free', 600) . 's)');
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // ----- Traitement du résultat discover_all -----
    if (isset($data['discover_result'])) {
        foreach ($data['discover_result'] as $eqLogicId => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            /** @var Nut_free $eqLogic */
            $eqLogic = Nut_free::byId($eqLogicId);
            if (!is_object($eqLogic)) {
                log::add('Nut_free', 'warning', '[CALLBACK] discover_result :: eqLogic introuvable : ' . $eqLogicId);
                continue;
            }
            $equipment = $eqLogic->getName();
            if (isset($payload['error'])) {
                log::add('Nut_free', 'error', '[CALLBACK][' . $equipment . '] discover_result :: erreur :: ' . $payload['error']);
                $eqLogic->setConfiguration('discover_error', $payload['error']);
                $eqLogic->setConfiguration('discover_status', 'error');
                $eqLogic->save();
                continue;
            }
            Nut_free::createDynamicCmd($eqLogic, $payload);
            log::add('Nut_free', 'info', '[CALLBACK][' . $equipment . '] discover_result :: synchronisation terminée');
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // ----- Traitement des mises à jour -----
    if (!isset($data['update']) || !is_array($data['update'])) {
        log::add('Nut_free', 'debug', '[CALLBACK] Aucune clé "update" dans le payload');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    foreach ($data['update'] as $eqLogicId => $values) {
        if (!is_array($values)) {
            continue;
        }

        /** @var Nut_free $eqLogic */
        $eqLogic = Nut_free::byId($eqLogicId);
        if (!is_object($eqLogic)) {
            log::add('Nut_free', 'warning', '[CALLBACK] eqLogic introuvable : ' . $eqLogicId);
            continue;
        }

        $equipment = $eqLogic->getName();

        if (!$eqLogic->getIsEnable()) {
            log::add('Nut_free', 'debug', '[CALLBACK][' . $equipment . '] désactivé, ignoré');
            continue;
        }

        $updated = false;
        foreach ($values as $logicalId => $value) {
            $cmd = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                log::add('Nut_free', 'debug', '[CALLBACK][' . $equipment . '] Commande introuvable : ' . $logicalId);
                continue;
            }
            $cmd->event($value);
            $updated = true;
            log::add('Nut_free', 'debug', '[CALLBACK][' . $equipment . '] ' . $logicalId . ' = ' . $value);
        }

        // Dériver les commandes marquées derivedFrom dont la valeur n'est pas déjà envoyée par le daemon
        foreach ($eqLogic->getCmd('info') as $cmd) {
            $derivedFrom = $cmd->getConfiguration('derivedFrom', '');
            if (empty($derivedFrom) || !isset($values[$derivedFrom])) continue;
            if (array_key_exists($cmd->getLogicalId(), $values)) continue; // déjà fourni par le daemon

            $sourceValue = $values[$derivedFrom];
            $result      = $sourceValue;

            if ($cmd->getLogicalId() === 'ups_status_label') {
                $result = Nut_free::translateUpsStatus($sourceValue);
            } elseif ($cmd->getUnite() === 'min' && trim($sourceValue) !== '-1' && is_numeric($sourceValue)) {
                $result = (string) round((float) $sourceValue / 60, 2);
            }

            $cmd->event($result);
            $updated = true;
            log::add('Nut_free', 'debug', '[CALLBACK][' . $equipment . '] ' . $cmd->getLogicalId() . ' = ' . $result . ' (dérivé de ' . $derivedFrom . ')');
        }

        if ($updated) {
            $eqLogic->refreshWidget();
            log::add('Nut_free', 'info', '[CALLBACK][' . $equipment . '] Widget rafraîchi');
        }
    }

    echo json_encode(['status' => 'ok']);

} catch (\Throwable $e) {
    log::add('Nut_free', 'error', '[CALLBACK] Exception :: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
