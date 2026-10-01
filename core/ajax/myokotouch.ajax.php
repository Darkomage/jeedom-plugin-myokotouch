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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../../core/php/myokotouch.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    switch (init('action')) {

        /**
         * Teste la connexion à la chaudière et retourne la liste des composants détectés.
         */
        case 'testConnection':
            // Priorité aux valeurs du formulaire (non encore sauvegardées),
            // sinon fallback sur la config enregistrée en base.
            $host     = trim(init('host'))     ?: trim(config::byKey('okofen_host',     'myokotouch', ''));
            $port     = (int)(init('port')     ?: config::byKey('okofen_port',     'myokotouch', 4321));
            $password = trim(init('password')) ?: trim(config::byKey('okofen_password', 'myokotouch', ''));
            if ($host === '' || $password === '') {
                throw new Exception(__('Adresse ou mot de passe non renseigné', __FILE__));
            }
            if ($port <= 0) { $port = 4321; }
            $allData = OKO_parseChaudiere::fetchAll($host, $port, $password);
            $components = OKO_parseChaudiere::detectComponents($allData);

            $summary = [];
            foreach ($components as $c) {
                $name = isset($allData[$c]['name']['val']) && $allData[$c]['name']['val'] !== ''
                    ? $allData[$c]['name']['val']
                    : null;
                $summary[] = $name ? ($c . ' [' . $name . ']') : $c;
            }

            // Persister les valeurs en base avant de démarrer le démon
            // (deamon_start() lit la config depuis la DB via getApiConfig())
            config::save('okofen_host',     $host,     'myokotouch');
            config::save('okofen_port',     $port,     'myokotouch');
            config::save('okofen_password', $password, 'myokotouch');

            $daemonStarted = false;
            $daemonInfo = myokotouch::deamon_info();
            if ($daemonInfo['state'] === 'nok' && $daemonInfo['launchable'] === 'ok') {
                try {
                    myokotouch::deamon_start();
                    $daemonStarted = true;
                    log::add('myokotouch', 'info', '[testConnection] démon démarré automatiquement');
                } catch (Exception $e) {
                    log::add('myokotouch', 'warning', '[testConnection] impossible de démarrer le démon : ' . $e->getMessage());
                }
            }

            ajax::success(['components' => implode(', ', $summary), 'daemonStarted' => $daemonStarted]);
            break;

        /**
         * Synchronise les équipements depuis la chaudière (création / mise à jour).
         */
        case 'syncEquipments':
            $result = myokotouch::syncFromOkofen();
            ajax::success($result);
            break;

        /**
         * Supprime et recrée toutes les commandes d'un équipement.
         * nameMode : 'default' (noms config) ou 'raw' (clés brutes Okofen)
         */
        case 'regenerateCommands':
            $eqLogicId = (int) init('eqLogicId');
            $nameMode  = in_array(init('nameMode'), ['default', 'raw'], true) ? init('nameMode') : 'default';
            $cmdMode   = in_array(init('cmdMode'), ['standard', 'expert'], true) ? init('cmdMode') : null;
            myokotouch::regenerateCommands($eqLogicId, $nameMode, $cmdMode);
            ajax::success();
            break;

        // Le diagnostic est servi par un endpoint dédié en téléchargement HTTP
        // (core/php/debugReport.php) — la CSP de Jeedom bloque les URL blob: en AJAX.
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
