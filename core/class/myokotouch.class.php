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

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class myokotouch extends eqLogic
{
    /** Cache de la version lue depuis info.json (source unique de vérité). */
    private static $_pluginVersion = null;

    /**
     * Retourne la version du plugin lue depuis plugin_info/info.json.
     * Évite toute dérive avec une constante codée en dur (qui restait figée aux releases).
     */
    public static function getPluginVersion(): string
    {
        if (self::$_pluginVersion === null) {
            self::$_pluginVersion = '?';
            $infoPath = __DIR__ . '/../../plugin_info/info.json';
            if (is_file($infoPath)) {
                $info = json_decode(file_get_contents($infoPath), true);
                if (is_array($info) && !empty($info['pluginVersion'])) {
                    self::$_pluginVersion = $info['pluginVersion'];
                }
            }
        }
        return self::$_pluginVersion;
    }

    /*     * *********************Méthodes d'instance************************* */

    private $_previousCmdMode = null;

    public function preInsert() {}
    public function postInsert() {}

    public function preUpdate() {
        // Lecture directe en DB pour éviter tout cache objet (byId() peut retourner
        // l'instance déjà mise à jour en mémoire avant l'écriture en base).
        $row = DB::Prepare(
            'SELECT configuration FROM `eqLogic` WHERE id = :id',
            [':id' => $this->getId()],
            DB::FETCH_TYPE_ROW
        );
        if (is_array($row) && isset($row['configuration'])) {
            $cfg = json_decode($row['configuration'], true) ?: [];
            $this->_previousCmdMode = $cfg['cmd_mode'] ?? 'standard';
        }
    }

    public function postUpdate() {
        $newMode = $this->getConfiguration('cmd_mode', 'standard');
        if ($this->_previousCmdMode === 'standard' && $newMode === 'expert') {
            myokotouch::regenerateCommands($this->getId(), 'default', 'expert');
            log::add('myokotouch', 'info', '[postUpdate] standard→expert détecté, regen lancé pour eqLogic ' . $this->getId());
        }
    }

    public function preSave() {}

    /**
     * Fonction exécutée automatiquement après la sauvegarde de l'équipement.
     * Crée (ou maintient) la commande "Rafraîchir" — pattern Jeedom v4 (cf. plugin Virtual).
     */
    public function postSave()
    {
        $refresh = $this->getCmd(null, 'refresh');
        if (!is_object($refresh)) {
            $refresh = cmd::byEqLogicIdCmdName($this->getId(), __('Rafraîchir', __FILE__));
        }
        if (!is_object($refresh)) {
            $refresh = new myokotouchCmd();
            $refresh->setLogicalId('refresh');
            $refresh->setName(__('Rafraîchir', __FILE__));
            $refresh->setIsVisible(1);
        }
        $refresh->setType('action');
        $refresh->setSubType('other');
        $refresh->setEqLogic_id($this->getId());
        $refresh->save();
    }

    public function preRemove() {}
    public function postRemove() {}


    /*     * ***********************Methodes statiques************************ */

    /**
     * Lit la configuration globale du plugin (IP, port, mot de passe, polling).
     * Lève une Exception si host ou password sont absents.
     */
    public static function getApiConfig(): array
    {
        $host     = config::byKey('okofen_host', 'myokotouch', '');
        $port     = (int) config::byKey('okofen_port', 'myokotouch', 4321);
        $password = config::byKey('okofen_password', 'myokotouch', '');
        $polling  = (int) config::byKey('okofen_polling', 'myokotouch', 3000);

        if (trim($host) === '') {
            throw new Exception(__('Adresse Okofen non configurée (Plugins → MyOkoTouch → Configuration)', __FILE__));
        }
        if (trim($password) === '') {
            throw new Exception(__('Mot de passe Okofen non configuré (Plugins → MyOkoTouch → Configuration)', __FILE__));
        }
        if ($port <= 0) {
            $port = 4321;
        }
        if ($polling < 2500) {
            $polling = 2500;
        }

        log::add('myokotouch', 'debug', 'getApiConfig: host=' . $host . ' port=' . $port . ' polling=' . $polling . 'ms');

        return [
            'host'     => $host,
            'port'     => $port,
            'password' => $password,
            'polling'  => $polling,
        ];
    }

    /**
     * Synchronise les équipements depuis la chaudière Okofen.
     * Crée ou met à jour un eqLogic par composant détecté, puis génère les commandes.
     * Retourne un résumé : ['created' => N, 'updated' => N, 'list' => [...noms...]].
     */
    public static function syncFromOkofen(): array
    {
        // Si le démon tourne, router la lecture /all? par sa file FIFO : cela respecte le
        // délai minimum de 2500 ms imposé par la chaudière et évite la collision avec le
        // polling (qui provoque l'erreur « zone de temporisation »). Le démon rappellera
        // jeedomDaemon.php avec les données complètes (_syncall) pour effectuer la sync.
        // Démon arrêté → lecture directe (aucun polling concurrent, pas de conflit possible).
        $info = self::deamon_info();
        if (($info['state'] ?? 'nok') === 'ok') {
            self::sendToSocket(['action' => 'syncall']);
            log::add('myokotouch', 'info', '[syncall] synchronisation mise en file du démon');
            return ['queued' => true];
        }

        $cfg     = self::getApiConfig();
        $allData = OKO_parseChaudiere::fetchAll($cfg['host'], $cfg['port'], $cfg['password']);
        return self::syncFromOkofenData($allData);
    }

    /**
     * Crée/met à jour les équipements et leurs commandes à partir d'un /all? déjà lu.
     * Appelé soit en direct (démon arrêté), soit depuis le callback _syncall du démon
     * (lecture passée par la file, respectant le délai chaudière).
     */
    public static function syncFromOkofenData(array $allData): array
    {
        $components = OKO_parseChaudiere::detectComponents($allData);

        log::add('myokotouch', 'info', 'syncFromOkofen: démarrage — composants détectés: ' . implode(', ', $components));

        $created = 0;
        $updated = 0;
        $list    = [];

        // Noms par défaut pour les composants sans champ 'name'
        $defaultNames = [
            'system'     => 'Système',
            'weather'    => 'Météo',
            'forecast'   => 'Prévisions météo',
            'error'      => 'Erreurs',
            'stirling'   => 'Stirling',
            'power'      => 'Compteur énergie',
            'hk'         => 'Chauffage',
            'ww'         => 'ECS',
            'pe'         => 'Chaudière',
            'circ'       => 'Circulateur',
            'pu'         => 'Accumulateur',
            'sk'         => 'Solaire',
            'se'         => 'Gain solaire',
            'wireless'   => 'Capteur sans fil',
            'thirdparty' => 'Tiers',
        ];

        foreach ($components as $component) {
            $logicalId = 'oko_' . $component;

            $eqLogic = myokotouch::byLogicalId($logicalId, 'myokotouch');
            $isNew   = !is_object($eqLogic);

            if ($isNew) {
                $eqLogic = new myokotouch();
                $eqLogic->setEqType_name('myokotouch');
                $eqLogic->setLogicalId($logicalId);
                // Appliquer le mode commandes par défaut défini dans la config globale du plugin
                $defaultCmdMode = config::byKey('cmd_mode_default', 'myokotouch', 'standard');
                $eqLogic->setConfiguration('cmd_mode', $defaultCmdMode);
            }

            // Nom de l'équipement
            $componentData = $allData[$component] ?? [];
            $nameField = $componentData['name'] ?? null;
            if (is_array($nameField) && isset($nameField['val']) && (string)$nameField['val'] !== '') {
                // Format avec métadonnées : {"val":"ECS Vie","length":20,"text":"..."}
                $name = $nameField['val'];
            } elseif (is_string($nameField) && $nameField !== '') {
                // Format sans métadonnées (réponse sans ?) : "ECS Vie"
                $name = $nameField;
            } else {
                $type      = self::detectType($component);
                $numSuffix = ltrim($component, 'abcdefghijklmnopqrstuvwxyz'); // '' ou '1', '2'...
                $baseName  = $defaultNames[$type] ?? ucfirst($type);
                $name      = $numSuffix !== '' ? $baseName . ' ' . $numSuffix : $baseName;
            }

            $eqLogic->setName($name);
            $eqLogic->setConfiguration('oko_component', $component);
            $eqLogic->setConfiguration('oko_type', self::detectType($component));
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->save();

            log::add('myokotouch', 'debug', 'syncFromOkofen: ' . ($isNew ? 'création' : 'mise à jour') . ' -> ' . $name . ' (' . $component . ')');

            self::syncCommands($eqLogic, $componentData);

            $list[] = $name . ' (' . $component . ')';
            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        log::add('myokotouch', 'info', 'syncFromOkofen: terminé — ' . $created . ' créés, ' . $updated . ' mis à jour.');

        return [
            'created' => $created,
            'updated' => $updated,
            'list'    => $list,
        ];
    }

    /**
     * Charge le fichier de configuration des commandes par type de composant.
     * Retourne le tableau ou [] si le fichier est absent.
     */
    private static $_commandsConfig = null;

    public static function loadCommandsConfig(): array
    {
        if (self::$_commandsConfig === null) {
            $path = dirname(__FILE__) . '/../config/commands.php';
            self::$_commandsConfig = file_exists($path) ? (require $path) : [];
        }
        return self::$_commandsConfig;
    }

    /**
     * Crée ou met à jour les commandes d'un eqLogic à partir des données JSON du composant.
     * - Commande INFO pour chaque variable
     * - Commande ACTION pour les variables sans préfixe L_ (writables)
     *
     * Mode standard : seules les variables de la liste blanche (commands.php) sont exposées.
     * Mode expert   : toutes les variables sont exposées.
     * Noms existants préservés sauf si _regen_name_mode est défini (appel depuis regenerateCommands).
     */
    public static function syncCommands(myokotouch $eqLogic, array $componentData): void
    {
        $okoComponent = $eqLogic->getConfiguration('oko_component'); // ex: 'hk1', 'ww2'
        $okoType      = $eqLogic->getConfiguration('oko_type', '');
        $cmdMode      = $eqLogic->getConfiguration('cmd_mode', 'standard');
        $regenMode    = $eqLogic->getConfiguration('_regen_name_mode'); // null = sync normale

        $cfg          = self::loadCommandsConfig();
        $typeNames    = $cfg[$okoType]['names']  ?? [];
        $boundsConfig = $cfg[$okoType]['bounds'] ?? [];
        $whitelist    = ($cmdMode === 'standard' && isset($cfg[$okoType]['standard']))
                     ? $cfg[$okoType]['standard']
                     : null;  // null = mode expert, pas de filtre

        // Pré-indexer toutes les commandes existantes par [type][logicalId]
        // et par [type][nom] pour retrouver les anciennes commandes nommées avec la clé brute
        $existingByLogicalId = [];
        $existingByName      = [];
        foreach (($eqLogic->getCmd() ?: []) as $cmd) {
            $t = $cmd->getType();
            $existingByLogicalId[$t][$cmd->getLogicalId()] = $cmd;
            $existingByName[$t][$cmd->getName()]            = $cmd;
        }

        // Détecter les labels text en doublon (deux variables peuvent avoir le même text)
        $textCounts = [];
        foreach ($componentData as $k => $vd) {
            if (is_string($vd) && substr($k, -5) === '_info') continue;
            if ($whitelist !== null && !in_array($k, $whitelist, true)) continue;
            $configName = $typeNames[$k] ?? null;
            $t = $configName ?? (is_array($vd) ? ($vd['text'] ?? $k) : $k);
            $textCounts[$t] = ($textCounts[$t] ?? 0) + 1;
        }

        foreach ($componentData as $key => $varData) {
            // Ignorer les clés *_info (ex: "hk_info", "ww_info") — description textuelle
            if (is_string($varData) && substr($key, -5) === '_info') {
                continue;
            }

            // Mode standard : ignorer les variables hors liste blanche
            if ($whitelist !== null && !in_array($key, $whitelist, true)) {
                continue;
            }

            $isReadOnly = (strpos($key, 'L_') === 0);
            $factor     = is_array($varData) ? ($varData['factor'] ?? 1) : 1;
            $unit       = is_array($varData) ? ($varData['unit']   ?? '')   : '';

            // Nom : config > text Okofen > clé brute
            $configName = $typeNames[$key] ?? null;
            $rawText    = $configName ?? (is_array($varData) ? ($varData['text'] ?? $key) : $key);

            // Garde-fou encodage : les chaînes Okofen (unité, label) doivent être de l'UTF-8
            // valide avant stockage, sinon °C s'affiche ?C. Repli défensif depuis Latin-1.
            $unit    = self::ensureUtf8($unit);
            $rawText = self::ensureUtf8($rawText);

            // Certains firmwares Okofen émettent « ? » au lieu de « ° » (détruit à la source) :
            // on normalise les unités connues (« ?C » → « °C »).
            $unit    = self::normalizeUnit($unit);

            // Suffixe systématique avec le composant, + clé si le label est partagé
            $text = ($textCounts[$rawText] ?? 1) > 1
                ? $rawText . ' (' . $okoComponent . ' / ' . $key . ')'
                : $rawText . ' (' . $okoComponent . ')';

            // ── Commande INFO ──────────────────────────────────────────────
            $infoSubType = self::determineInfoSubType($varData);

            // Chercher par logicalId, puis par l'ancien nom = clé brute (rétrocompat)
            $infoCmd = $existingByLogicalId['info'][$key]
                    ?? $existingByName['info'][$key]
                    ?? new myokotouchCmd();

            $infoCmd->setEqLogic_id($eqLogic->getId());
            $infoCmd->setLogicalId($key);
            // Nom : préservé si commande existante, sauf en mode régénération
            if (!$infoCmd->getId() || $regenMode !== null) {
                $nameToUse = ($regenMode === 'raw') ? $key : $text;
                $infoCmd->setName($nameToUse);
            }
            $infoCmd->setType('info');
            $infoCmd->setSubType($infoSubType);
            $infoCmd->setConfiguration('factor', $factor);
            if ($unit !== '') {
                $infoCmd->setUnite($unit);
                $infoCmd->setConfiguration('unite', $unit);
            }
            // Bornes réalistes pour les infos numériques (jauges / échelle graphes) : les L_
            // n'ont que des sentinelles INT16 → on résout via commands.php / catégories / unité.
            if ($infoSubType === 'numeric') {
                $bounds = self::resolveInfoBounds($key, $varData, $componentData, $boundsConfig, $unit, $factor);
                if ($bounds !== null) {
                    $infoCmd->setConfiguration('minValue', $bounds[0]);
                    $infoCmd->setConfiguration('maxValue', $bounds[1]);
                }
            }
            $infoCmd->save();

            log::add('myokotouch', 'debug', '[syncCmd] ' . $eqLogic->getName() . ' :: ' . $key . ' (info/' . $infoSubType . ')');

            // ── Commande ACTION (variables writables uniquement) ────────────
            if (!$isReadOnly && is_array($varData)) {
                $actionSubType = self::determineActionSubType($varData);
                $actLogicalId  = $key . '_set';

                $actCmd = $existingByLogicalId['action'][$actLogicalId]
                       ?? $existingByName['action'][$key . ' (réglage)']
                       ?? new myokotouchCmd();

                $actCmd->setEqLogic_id($eqLogic->getId());
                $actCmd->setLogicalId($actLogicalId);
                // Nom : préservé si commande existante, sauf en mode régénération
                if (!$actCmd->getId() || $regenMode !== null) {
                    $actNameToUse = ($regenMode === 'raw') ? $key . '_set' : $text . ' (réglage)';
                    $actCmd->setName($actNameToUse);
                }
                $actCmd->setType('action');
                $actCmd->setSubType($actionSubType);
                $actCmd->setConfiguration('factor', $factor);
                $actCmd->setConfiguration('oko_component', $eqLogic->getConfiguration('oko_component'));
                $actCmd->setConfiguration('oko_var', $key);

                if ($actionSubType === 'select' && isset($varData['format'])) {
                    $actCmd->setConfiguration('listValue', self::buildListValue($varData['format']));
                }
                if ($actionSubType === 'slider') {
                    // subType slider ⟺ hasRealBounds : min/max sont de vraies bornes fonctionnelles.
                    $actCmd->setConfiguration('minValue', $varData['min'] * $factor);
                    $actCmd->setConfiguration('maxValue', $varData['max'] * $factor);
                    $actCmd->setConfiguration('step', ($factor > 0 && $factor < 1) ? $factor : 1);
                }

                $actCmd->save();

                log::add('myokotouch', 'debug', '[syncCmd] ' . $eqLogic->getName() . ' :: ' . $key . '_set (action/' . $actionSubType . ')');
            }
        }
    }

    /**
     * Supprime toutes les commandes d'un équipement (sauf 'refresh') et les recrée
     * depuis les données Okofen actuelles.
     *
     * $nameMode : 'default' = noms du fichier config (ou text Okofen)
     *             'raw'     = clés brutes Okofen (L_roomtemp_act, mode_auto…)
     */
    /**
     * Demande une régénération des commandes au démon Python (via socket).
     * Le démon respecte le délai Okofen, lit /all?, puis rappelle jeedomDaemon.php
     * avec les données brutes du composant (clé _regen).
     *
     * $nameMode : 'default' = noms du fichier config (ou text Okofen)
     *             'raw'     = clés brutes Okofen (L_roomtemp_act, mode_auto…)
     * $cmdMode  : si fourni, persiste le nouveau mode et l'envoie au démon
     */
    public static function regenerateCommands(int $eqLogicId, string $nameMode = 'default', ?string $cmdMode = null): void
    {
        $eqLogic = eqLogic::byId($eqLogicId);
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement introuvable : ' . $eqLogicId);
        }

        // Persister le nouveau mode avant que le démon rappelle — il doit être en DB
        if ($cmdMode !== null) {
            $eqLogic->setConfiguration('cmd_mode', $cmdMode);
            $eqLogic->save();
        }

        $component = $eqLogic->getConfiguration('oko_component');
        if (!$component) {
            throw new Exception('Composant non configuré pour l\'équipement ' . $eqLogicId);
        }

        $payload = [
            'action'    => 'regen',
            'eqLogicId' => $eqLogicId,
            'component' => $component,
            'nameMode'  => $nameMode,
        ];
        if ($cmdMode !== null) {
            $payload['cmdMode'] = $cmdMode;
        }

        self::sendToSocket($payload);
        log::add('myokotouch', 'info', '[regen] demande envoyée au démon pour ' . $component . ' (eqLogicId=' . $eqLogicId . ')');
    }

    /**
     * Recrée les commandes d'un équipement à partir de données Okofen fournies par le démon.
     * Appelé depuis jeedomDaemon.php lors du callback _regen.
     *
     * $componentData : données brutes du composant (avec métadonnées factor/format/text/unit)
     * $nameMode      : 'default' ou 'raw'
     * $cmdMode       : si fourni, écrase la config cmd_mode de l'équipement
     */
    public static function regenerateCommandsFromData(int $eqLogicId, array $componentData, string $nameMode = 'default', ?string $cmdMode = null): void
    {
        $eqLogic = eqLogic::byId($eqLogicId);
        if (!is_object($eqLogic)) {
            log::add('myokotouch', 'warning', '[regen] équipement introuvable: ' . $eqLogicId);
            return;
        }

        if ($cmdMode !== null) {
            $eqLogic->setConfiguration('cmd_mode', $cmdMode);
        }

        // Supprimer toutes les commandes sauf 'refresh'
        foreach (($eqLogic->getCmd() ?: []) as $cmd) {
            if ($cmd->getLogicalId() !== 'refresh') {
                $cmd->remove();
            }
        }

        $component = $eqLogic->getConfiguration('oko_component');
        $eqLogic->setConfiguration('_regen_name_mode', $nameMode);
        self::syncCommands($eqLogic, $componentData);
        $eqLogic->setConfiguration('_regen_name_mode', null);
        $eqLogic->save();

        log::add('myokotouch', 'info', '[regen] ' . $component . ' terminé — mode noms: ' . $nameMode . ($cmdMode ? ' cmdMode: ' . $cmdMode : ''));
    }

    /**
     * Bornes réalistes par catégorie (valeurs physiques, après factor). Réglables ici.
     * Utilisées pour les infos L_ qui ne portent que des sentinelles INT16.
     */
    private static $BOUNDS_CATEGORIES = [
        'ext_temp'   => [-20, 45],   // température extérieure
        'room_temp'  => [-5, 45],    // température ambiante mesurée
        'water_temp' => [0, 100],    // départ / retour / chaudière / ECS, consignes techniques
        'percent'    => [0, 100],    // modulation, nébulosité, …
    ];

    /**
     * Indique si une variable porte de VRAIES bornes fonctionnelles (et non les sentinelles
     * INT16 -32768/32767, qui signifient « pas de capteur / pas de borne »).
     */
    public static function hasRealBounds($varData): bool
    {
        return is_array($varData)
            && isset($varData['min'], $varData['max'])
            && $varData['min'] > -32768 && $varData['max'] < 32767;
    }

    /**
     * Détermine le subType Jeedom d'une commande info à partir des métadonnées Okofen.
     * 'numeric' uniquement si la valeur est réellement numérique (sinon string : heures, météo…).
     */
    public static function determineInfoSubType($varData): string
    {
        if (is_string($varData)) {
            return 'string';
        }
        if (isset($varData['format'])) {
            $parts = explode('|', $varData['format']);
            return (count($parts) === 2) ? 'binary' : 'string';
        }
        return is_numeric($varData['val'] ?? null) ? 'numeric' : 'string';
    }

    /**
     * Détermine le subType Jeedom d'une commande action à partir des métadonnées Okofen.
     * 'slider' uniquement si de vraies bornes existent ; sinon 'other' (saisie libre).
     */
    public static function determineActionSubType(array $varData): string
    {
        if (isset($varData['format'])) {
            return 'select';
        }
        if (self::hasRealBounds($varData)) {
            return 'slider';
        }
        return 'other';
    }

    /**
     * Résout les bornes [min, max] (valeurs physiques) d'une info numérique, ou null si aucune.
     * Ordre : vraies bornes JSON → config commands.php (catégorie / '@actionVar' / [a,b]) →
     *         auto par unité (%) → rien.
     *
     * $boundsConfig : map 'variable' => 'catégorie' | '@actionVar' | [min, max] (depuis commands.php)
     */
    public static function resolveInfoBounds(string $key, $varData, array $componentData, array $boundsConfig, string $unit, $factor): ?array
    {
        // 1. Vraies bornes JSON de la variable elle-même
        if (self::hasRealBounds($varData)) {
            return [$varData['min'] * $factor, $varData['max'] * $factor];
        }

        // 2. Configuration commands.php
        if (isset($boundsConfig[$key])) {
            $b = $boundsConfig[$key];

            // 2a. Dérivation depuis l'action correspondante : '@temp_heat'
            if (is_string($b) && strlen($b) > 1 && $b[0] === '@') {
                $actVar  = substr($b, 1);
                $actData = $componentData[$actVar] ?? null;
                if (self::hasRealBounds($actData)) {
                    $f = $actData['factor'] ?? 1;
                    return [$actData['min'] * $f, $actData['max'] * $f];
                }
                return null; // action introuvable/sans bornes → pas de jauge fausse
            }

            // 2b. Catégorie nommée
            if (is_string($b) && isset(self::$BOUNDS_CATEGORIES[$b])) {
                return self::$BOUNDS_CATEGORIES[$b];
            }

            // 2c. Bornes explicites [min, max]
            if (is_array($b) && count($b) === 2 && is_numeric($b[0]) && is_numeric($b[1])) {
                return [$b[0], $b[1]];
            }
        }

        // 3. Auto par unité : pourcentage
        if ($unit === '%') {
            return self::$BOUNDS_CATEGORIES['percent'];
        }

        // 4. Aucune borne réaliste connue
        return null;
    }

    /**
     * Convertit le format Okofen en format listValue Jeedom v4.
     * Entrée  : "0:Arrêt|1:Auto|2:Marche forcée"
     * Sortie  : "0|Arrêt;1|Auto;2|Marche forcée"  (value|label séparés par ;)
     */
    public static function buildListValue(string $format): string
    {
        $parts = explode('|', $format);
        $items = [];
        foreach ($parts as $part) {
            [$val, $label] = array_pad(explode(':', $part, 2), 2, $part);
            $items[] = trim($val) . '|' . trim($label);
        }
        return implode(';', $items);
    }

    /**
     * Extrait le type de base d'un identifiant de composant.
     * Ex : 'hk1' → 'hk', 'ww2' → 'ww', 'system' → 'system'
     */
    public static function detectType(string $component): string
    {
        preg_match('/^([a-z]+)/', $component, $m);
        return $m[1] ?? $component;
    }

    /**
     * Garantit qu'une chaîne est de l'UTF-8 valide (repli défensif depuis Latin-1).
     * Empêche la corruption des caractères spéciaux (ex : °C → ?C) au stockage.
     */
    public static function ensureUtf8(string $str): string
    {
        if ($str === '' || mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Corrige les unités mutilées par certains firmwares Okofen qui émettent littéralement
     * « ? » à la place de « ° » (le caractère est détruit à la source, irrécupérable par
     * décodage). En contexte chauffage, « ?C » est sans ambiguïté « °C ». Table extensible.
     */
    private static $UNIT_FIXES = [
        '?C' => '°C',
    ];

    public static function normalizeUnit(string $unit): string
    {
        return self::$UNIT_FIXES[$unit] ?? $unit;
    }

    /*     * ***********************Gestion du démon************************* */

    /**
     * Retourne l'état du démon pour Jeedom (appelé par le core).
     */
    public static function deamon_info(): array
    {
        $return = [
            'log'        => 'myokotouch_daemon',
            'state'      => 'nok',
            'launchable' => 'ok',
        ];

        $pidFile = jeedom::getTmpFolder('myokotouch') . '/demond.pid';
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid !== '' && posix_getsid((int) $pid) !== false) {
                $return['state'] = 'ok';
            }
        }

        try {
            self::getApiConfig();
        } catch (Exception $e) {
            $return['launchable']         = 'nok';
            $return['launchable_message'] = $e->getMessage();
        }

        log::add('myokotouch', 'debug', '[deamon_info] state=' . $return['state'] . ' launchable=' . $return['launchable']);

        return $return;
    }

    /**
     * Démarre le démon Python.
     */
    public static function deamon_start(array $_params = []): void
    {
        self::deamon_stop();

        $cfg        = self::getApiConfig();
        $daemonPath = realpath(dirname(__FILE__) . '/../../resources/demond');
        $pidFile    = jeedom::getTmpFolder('myokotouch') . '/demond.pid';
        $socketPort = (int) config::byKey('socketport', 'myokotouch', 55009);
        $logFile    = log::getPathToLog('myokotouch_daemon');

        // Niveau de log : log::getLogLevel() retourne un code Monolog numérique → mapper vers string Python
        $monologLevel = log::getLogLevel('myokotouch');
        $logLevelMap  = ['100' => 'debug', '200' => 'info', '250' => 'info', '300' => 'warning', '400' => 'error', '500' => 'error', '1000' => 'error'];
        $logLevel     = $logLevelMap[(string) $monologLevel] ?? 'info';

        $apiKey      = jeedom::getApiKey();   // clé globale Jeedom (cohérente avec jeedomDaemon.php)
        // Note : jeedom_com ajoute automatiquement ?apikey=X — ne pas l'inclure ici
        $callbackUrl = network::getNetworkAccess('internal')
                     . '/plugins/myokotouch/core/php/jeedomDaemon.php';

        // Python système : les dépendances (requests/pyserial/pyudev) sont fournies par les
        // paquets apt déclarés dans plugin_info/packages.json (python3-requests, etc.).
        $cmd = 'python3 ' . $daemonPath . '/demond.py'
             . ' --loglevel '   . escapeshellarg($logLevel)
             . ' --callback '   . escapeshellarg($callbackUrl)
             . ' --apikey '     . escapeshellarg($apiKey)
             . ' --host '       . escapeshellarg($cfg['host'])
             . ' --port '       . (int) $cfg['port']
             . ' --password '   . escapeshellarg($cfg['password'])
             . ' --cycle '      . number_format($cfg['polling'] / 1000, 2, '.', '')
             . ' --socketport ' . $socketPort
             . ' --pid '        . escapeshellarg($pidFile);

        log::add('myokotouch', 'info', '[deamon_start] lancement: ' . $cmd);
        exec($cmd . ' >> ' . $logFile . ' 2>&1 &');

        // Attendre l'écriture du PID (max 10 s)
        $attempts = 0;
        while ($attempts < 10 && !file_exists($pidFile)) {
            sleep(1);
            $attempts++;
        }

        $pid = file_exists($pidFile) ? trim(file_get_contents($pidFile)) : '?';
        log::add('myokotouch', 'info', '[deamon_start] démon démarré — PID: ' . $pid);
    }

    /**
     * Envoie une commande JSON au démon Python via socket TCP.
     * Format : {"apikey":"...","action":"write","component":"hk1","var":"mode_auto","value":1}
     */
    public static function sendToSocket(array $payload): void
    {
        $socketport = (int) config::byKey('socketport', 'myokotouch', 55009);
        $apikey     = jeedom::getApiKey();  // même clé que celle passée au démon au démarrage
        $payload['apikey'] = $apikey;

        $fp = @fsockopen('127.0.0.1', $socketport, $errno, $errstr, 1);
        if ($fp === false) {
            throw new Exception('[write] Socket connexion impossible (port ' . $socketport . '): ' . $errstr . ' (' . $errno . ')');
        }
        fwrite($fp, json_encode($payload) . "\n");
        fclose($fp);
    }

    /**
     * Arrête le démon Python.
     */
    public static function deamon_stop(): void
    {
        $pidFile = jeedom::getTmpFolder('myokotouch') . '/demond.pid';
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0) {
                log::add('myokotouch', 'info', '[deamon_stop] envoi SIGTERM au PID ' . $pid);
                posix_kill($pid, SIGTERM);
                sleep(2);
            }
            unlink($pidFile);
        }
        // Tuer tout processus demond.py résiduel (crashs précédents, double lancement…)
        exec('pkill -f "resources/demond/demond.py" 2>/dev/null');
        sleep(1);
        log::add('myokotouch', 'info', '[deamon_stop] démon arrêté');
    }
}


class myokotouchCmd extends cmd
{
    /*     * *********************Methode d'instance************************* */

    /**
     * Exécution d'une commande.
     *
     * Cas 'refresh' : relit tous les registres du composant et met à jour les commandes info.
     * Autre cas : écriture d'une valeur sur un registre Okofen (commande _set).
     */
    public function execute($_options = array())
    {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {

            case 'refresh':
                try {
                    $cfg       = myokotouch::getApiConfig();
                    $component = $eqLogic->getConfiguration('oko_component');
                    if (!$component) {
                        log::add('myokotouch', 'warning', '[refresh] composant non configuré pour l\'équipement ' . $eqLogic->getName());
                        break;
                    }
                    log::add('myokotouch', 'debug', '[refresh] ' . $component . ': début lecture');
                    $allData = OKO_parseChaudiere::fetchAll($cfg['host'], $cfg['port'], $cfg['password']);
                    if (!isset($allData[$component])) {
                        log::add('myokotouch', 'warning', '[refresh] composant ' . $component . ' absent de la réponse Okofen');
                        break;
                    }
                    $count = 0;
                    foreach ($allData[$component] as $key => $varData) {
                        if (is_array($varData)) {
                            if (!isset($varData['val'])) {
                                continue;
                            }
                            $factor = $varData['factor'] ?? 1;
                            $val    = OKO_parseChaudiere::applyFactor($varData['val'], $factor);
                        } elseif (is_string($varData) || is_numeric($varData)) {
                            // Valeur directe (ex: L_statetext = "Mode confort actif")
                            $val = (string) $varData;
                        } else {
                            continue;
                        }
                        $eqLogic->checkAndUpdateCmd($key, $val);
                        log::add('myokotouch', 'debug', '[refresh] ' . $component . '.' . $key . ' = ' . $val);
                        $count++;
                    }
                    log::add('myokotouch', 'info', '[refresh] ' . $component . ': ' . $count . ' valeur(s) mises à jour');
                } catch (Exception $e) {
                    log::add('myokotouch', 'error', '[refresh] ' . $e->getMessage());
                }
                break;

            default:
                // Commande d'écriture (_set)
                $component = $this->getConfiguration('oko_component');
                $var       = $this->getConfiguration('oko_var');
                if (!$component || !$var) {
                    log::add('myokotouch', 'warning', '[write] commande ' . $this->getLogicalId() . ' sans oko_component/oko_var');
                    break;
                }
                $factor  = (float) $this->getConfiguration('factor', 1);
                $subType = $this->getSubType();

                // Calculer rawVal selon le sous-type (hors try pour pouvoir utiliser break normalement)
                if ($subType === 'slider') {
                    if (!isset($_options['slider'])) {
                        log::add('myokotouch', 'warning', '[write] slider sans valeur pour ' . $this->getLogicalId());
                        break;
                    }
                    $rawVal = (int) round((float) $_options['slider'] / ($factor > 0 ? $factor : 1));
                } elseif ($subType === 'select') {
                    if (!isset($_options['select'])) {
                        log::add('myokotouch', 'warning', '[write] select sans valeur pour ' . $this->getLogicalId());
                        break;
                    }
                    $rawVal = $_options['select'];
                } else {
                    $rawVal = 0;
                }

                log::add('myokotouch', 'info', '[write] ' . $component . '.' . $var . ' = ' . $rawVal . ' (factor=' . $factor . ')');

                try {
                    // Envoi au démon via socket — le démon exécute, parse la réponse
                    // et met à jour Jeedom immédiatement via send_change_immediate()
                    myokotouch::sendToSocket([
                        'action'    => 'write',
                        'component' => $component,
                        'var'       => $var,
                        'value'     => $rawVal,
                    ]);
                } catch (Exception $e) {
                    log::add('myokotouch', 'error', '[write] ' . $e->getMessage());
                }
                break;
        }
    }
}
