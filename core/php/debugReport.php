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
 * Génère et renvoie EN TÉLÉCHARGEMENT (Content-Disposition) un rapport de diagnostic :
 * JSON brut de la chaudière + métadonnées d'encodage + inventaire des commandes Jeedom.
 *
 * Endpoint HTTP dédié (et non AJAX/Blob) : la CSP de Jeedom bloque les URL blob:, donc on
 * sert un vrai fichier via un POST de formulaire. Credentials lus depuis le POST (form caché)
 * ou la config ; jamais inclus dans le rapport (hôte masqué, mot de passe absent).
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../core/php/myokotouch.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect('admin')) {
    http_response_code(401);
    die('401 - Accès non autorisé');
}

try {
    $host     = trim(init('host'))     ?: trim(config::byKey('okofen_host',     'myokotouch', ''));
    $port     = (int)(init('port')     ?: config::byKey('okofen_port',     'myokotouch', 4321));
    $password = trim(init('password')) ?: trim(config::byKey('okofen_password', 'myokotouch', ''));
    if ($host === '' || $password === '') {
        http_response_code(400);
        die('Adresse ou mot de passe non renseigné');
    }
    if ($port <= 0) { $port = 4321; }

    // Flux brut (octets non convertis) pour exposer l'encodage réel au support.
    $url = OKO_parseChaudiere::buildUrl($host, $port, $password) . '/all?';
    $raw = OKO_parseChaudiere::httpGetRaw($url);
    $rawBody     = $raw['body'];
    $contentType = $raw['content_type'];

    $detectedCharset = null;
    if (preg_match('/charset=([^\s;]+)/i', $contentType, $m)) {
        $detectedCharset = strtoupper(trim($m[1]));
    }

    // Corps normalisé UTF-8 pour parsing (mêmes règles que fetchAll).
    $utf8Body = OKO_parseChaudiere::toUtf8($rawBody, $contentType);
    $parsed   = json_decode($utf8Body, true);
    $parsedOk = is_array($parsed);

    // Inventaire des commandes Jeedom existantes (stockage vs réception).
    $cmdInventory = [];
    foreach (eqLogic::byType('myokotouch') as $eq) {
        foreach (($eq->getCmd() ?: []) as $cmd) {
            $cmdInventory[] = [
                'eqLogic'   => $eq->getName(),
                'logicalId' => $cmd->getLogicalId(),
                'name'      => $cmd->getName(),
                'type'      => $cmd->getType(),
                'subType'   => $cmd->getSubType(),
                'unite'     => $cmd->getUnite(),
            ];
        }
    }

    // Échantillons des octets non-ASCII (contexte lisible + hexa) : identifie l'encodage réel
    // sans hexdump (ex : « C2 B0 » = ° en UTF-8, « B0 » = ° en Latin-1, « 3F » = ? littéral).
    $nonAsciiSamples = [];
    if (preg_match_all('/.{0,12}[\x80-\xFF]+.{0,4}/s', $rawBody, $mm)) {
        foreach (array_slice(array_unique($mm[0]), 0, 15) as $sample) {
            $nonAsciiSamples[] = [
                'latin1' => mb_convert_encoding($sample, 'UTF-8', 'ISO-8859-1'),
                'hex'    => strtoupper(implode(' ', str_split(bin2hex($sample), 2))),
            ];
        }
    }

    // Version de l'interface JSON (indice de la génération du firmware Touch) : la page d'aide
    // de l'API commence par « Oekofen JSON Interface   V4.04b ». Lecture best-effort, après le
    // délai chaudière de 2500 ms entre deux lectures.
    $interfaceVersion = null;
    try {
        usleep(2600000);
        $help = OKO_parseChaudiere::httpGetRaw(OKO_parseChaudiere::buildUrl($host, $port, $password) . '/')['body'];
        if (preg_match('/JSON\s+Interface\s+(V?[0-9][\w.]*)/i', $help, $vm)) {
            $interfaceVersion = $vm[1];
        }
    } catch (Throwable $e) {
        $interfaceVersion = null;
    }

    // Masquage de l'hôte (les credentials ne doivent jamais quitter l'instance).
    $hostMasked = preg_replace('/[^.:]/', '*', $host);

    $report = [
        'generated_at'   => date('Y-m-d H:i:s'),
        'plugin_version' => myokotouch::getPluginVersion(),
        'host_masked'    => $hostMasked,
        'port'           => $port,
        'http'           => [
            'content_type'     => $contentType,
            'detected_charset' => $detectedCharset,
            'byte_length'      => strlen($rawBody),
            'valid_utf8_raw'   => mb_check_encoding($rawBody, 'UTF-8'),
        ],
        'okofen_json_interface' => $interfaceVersion,
        'okofen_parsed_ok' => $parsedOk,
        'non_ascii_samples' => $nonAsciiSamples,
        'jeedom_commands'  => $cmdInventory,
        // Corps brut tel que reçu (octets exacts : le support voit le vrai °C / ?C).
        // En Latin-1, les octets invalides y sont remplacés par U+FFFD : la version base64
        // ci-dessous reste la référence exacte.
        'okofen_raw_json'  => $rawBody,
        'okofen_raw_base64' => base64_encode($rawBody),
    ];

    // JSON_INVALID_UTF8_SUBSTITUTE : un corps Latin-1 ferait sinon échouer json_encode (fichier vide).
    $reportStr = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $filename  = 'okofen-debug_' . date('Y-m-d_H-i-s') . '.json';

    header('Content-Type: application/octet-stream; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($reportStr));
    header('Cache-Control: no-store');
    echo $reportStr;
    exit;

} catch (Throwable $e) {
    log::add('myokotouch', 'error', '[debugReport] ' . $e->getMessage());
    http_response_code(500);
    die('Erreur génération diagnostic : ' . $e->getMessage());
}
