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
 * Endpoint de callback HTTP appelé par le démon Python à chaque cycle de polling.
 *
 * Le démon envoie un HTTP POST avec du JSON structuré comme :
 *   { "hk1": { "L_roomtemp_act": 21.2, "mode_auto": 1, ... }, "ww1": { ... }, ... }
 *
 * Ce script met à jour les commandes info de chaque eqLogic correspondant.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../core/php/myokotouch.inc.php';

// Vérification de la clé API (passée en paramètre GET ou dans le JSON)
$apiKey = init('apikey');
if ($apiKey !== jeedom::getApiKey()) {
    log::add('myokotouch', 'warning', '[jeedomDaemon] clé API invalide');
    http_response_code(403);
    die('Forbidden');
}

$body = file_get_contents('php://input');
if (empty($body)) {
    log::add('myokotouch', 'warning', '[jeedomDaemon] corps de requête vide');
    die();
}

$data = json_decode($body, true);
if (!is_array($data)) {
    log::add('myokotouch', 'warning', '[jeedomDaemon] JSON invalide: ' . $body);
    die();
}

// Traitement du callback de synchronisation globale (envoyé par le démon après lecture /all?
// passée par sa file, en réponse à une demande « Synchroniser »).
if (isset($data['_syncall'])) {
    try {
        $allData = (array) ($data['_syncall']['allData'] ?? []);
        $res = myokotouch::syncFromOkofenData($allData);
        log::add('myokotouch', 'info', '[jeedomDaemon] syncall traité — ' . $res['created'] . ' créé(s), ' . $res['updated'] . ' mis à jour');
    } catch (Exception $e) {
        log::add('myokotouch', 'error', '[jeedomDaemon] syncall échoué: ' . $e->getMessage());
    }
    unset($data['_syncall']);
}

// Traitement du callback de régénération (envoyé par le démon après lecture /all?)
if (isset($data['_regen'])) {
    $regen = $data['_regen'];
    try {
        myokotouch::regenerateCommandsFromData(
            (int)  ($regen['eqLogicId']     ?? 0),
            (array)($regen['componentData'] ?? []),
                   ($regen['nameMode']      ?? 'default'),
                   ($regen['cmdMode']       ?? null)
        );
    } catch (Exception $e) {
        log::add('myokotouch', 'error', '[jeedomDaemon] regen échoué: ' . $e->getMessage());
    }
    unset($data['_regen']);
}

$updatedComponents = 0;
$updatedValues     = 0;

foreach ($data as $component => $vars) {
    if (!is_array($vars)) {
        continue;
    }

    $eqLogic = myokotouch::byLogicalId('oko_' . $component, 'myokotouch');
    if (!is_object($eqLogic) || !$eqLogic->getIsEnable()) {
        log::add('myokotouch', 'debug', '[jeedomDaemon] composant ignoré (absent ou désactivé): ' . $component);
        continue;
    }

    foreach ($vars as $key => $value) {
        $eqLogic->checkAndUpdateCmd($key, $value);
        log::add('myokotouch', 'debug', '[jeedomDaemon] ' . $component . '.' . $key . ' = ' . $value);
        $updatedValues++;
    }
    $updatedComponents++;
}

log::add('myokotouch', 'debug', '[jeedomDaemon] callback traité: ' . $updatedComponents . ' composant(s), ' . $updatedValues . ' valeur(s)');
