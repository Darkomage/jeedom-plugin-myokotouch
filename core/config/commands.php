<?php
/**
 * Configuration des commandes par type de composant Okofen.
 * Éditer ce fichier pour ajuster les listes et noms.
 *
 * - 'standard' : variables exposées en mode standard (liste blanche)
 * - 'names'    : noms des commandes info (remplace le champ 'text' Okofen)
 *                Couvre toutes les variables connues — modes standard ET expert.
 *                Le nom de la commande action = nom info + ' (réglage)'
 *                Si une clé est absente, fallback sur le champ 'text' Okofen.
 */
return [
    'hk' => [
        'standard' => [
            'L_roomtemp_act', 'L_flowtemp_act', 'L_flowtemp_set',
            'L_state', 'L_statetext', 'mode_auto', 'temp_heat', 'temp_setback',
        ],
        'names' => [
            // Lectures
            'L_roomtemp_act'  => 'Temp. ambiante',
            'L_roomtemp_set'  => 'Consigne ambiante',
            'L_flowtemp_act'  => 'Temp. départ',
            'L_flowtemp_set'  => 'Consigne départ',
            'L_comfort'       => 'Correction confort',
            'L_state'         => 'État',
            'L_statetext'     => 'État texte',
            'L_pump'          => 'Pompe chauffage',
            // Réglages
            'mode_off'        => 'Mode (off)',
            'mode_auto'       => 'Mode',
            'mode_dhw'        => 'Mode (ECS)',
            'time_prg'        => 'Choix programme',
            'temp_setback'    => 'Consigne réduit',
            'temp_heat'       => 'Consigne chauffage',
            'temp_vacation'   => 'Consigne vacances',
            'sensor_avg'      => 'Lissage capteur',
            'remote_override' => 'Demande Externe',
            'oekomode'        => 'Mode écolo',
            'name'            => 'Nom du circuit',
        ],
        'bounds' => [
            'L_roomtemp_act' => 'room_temp',
            'L_roomtemp_set' => '@temp_heat',   // dérive des bornes réelles de l'action consigne
            'L_flowtemp_act' => 'water_temp',
            'L_flowtemp_set' => 'water_temp',
        ],
    ],
    'ww' => [
        // Note : pas de L_temp_act dans ww — températures = L_ontemp_act / L_offtemp_act
        'standard' => [
            'L_ontemp_act', 'L_offtemp_act', 'L_temp_set', 'L_state', 'L_statetext', 'mode_auto',
        ],
        'names' => [
            // Lectures
            'L_ontemp_act'   => 'Temp. départ',
            'L_offtemp_act'  => 'Temp. coupure',
            'L_temp_set'     => 'Consigne ECS',
            'L_pump'         => 'Pompe ECS',
            'L_state'        => 'État',
            'L_statetext'    => 'État texte',
            // Réglages
            'mode_auto'      => 'Mode',
            'heat_once'      => 'Charge ECS unique',
            'temp_min_set'   => 'Temp. min ECS',
            'temp_max_set'   => 'Consigne ECS',
            'time_prg'       => 'Choix programme',
            'sensor_on'      => 'Capteur démarrage',
            'sensor_off'     => 'Capteur coupure',
            'smartstart'     => 'Anticipation charge',
            'use_boiler_heat'=> 'Récupération énergie',
            'oekomode'       => 'Mode écolo',
            'name'           => 'Nom du circuit',
        ],
        'bounds' => [
            'L_ontemp_act'  => 'water_temp',
            'L_offtemp_act' => 'water_temp',
            'L_temp_set'    => 'water_temp',
        ],
    ],
    'pe' => [
        'standard' => [
            'L_temp_act', 'L_frt_temp_act', 'L_state', 'L_statetext',
            'L_storage_fill', 'L_pellets_today', 'L_pellets_yesterday', 'L_modulation', 'mode',
        ],
        'names' => [
            // Températures
            'L_temp_act'          => 'Temp. chaudière',
            'L_temp_set'          => 'Consigne retour',
            'L_ext_temp'          => 'Temp. fumées',
            'L_frt_temp_act'      => 'Temp. flamme',
            'L_frt_temp_set'      => 'Consigne flamme',
            'L_frt_temp_end'      => 'Temp. max flamme',
            // État
            'L_state'             => 'État',
            'L_statetext'         => 'État texte',
            'L_br'                => 'Contact brûleur',
            'L_ak'                => 'Chaleur externe (AK)',
            'L_not'               => 'Arrêt urgence',
            'L_stb'               => 'Sécurité thermostat',
            'L_type'              => 'Type chaudière',
            // Combustion
            'L_modulation'        => 'Modulation',
            'L_currentairflow'    => 'Vitesse air combustion',
            'L_lowpressure'       => 'Dépression',
            'L_lowpressure_set'   => 'Consigne dépression',
            'L_fluegas'           => 'Vitesse ventil. fumées',
            'L_uw_speed'          => 'Vitesse UW',
            'L_uw'                => 'Commande UW',
            'L_uw_release'        => 'Temp. limite UW',
            'L_runtimeburner'     => 'Durée marche brûleur',
            'L_resttimeburner'    => 'Durée pause brûleur',
            // Pellets
            'L_storage_fill'      => 'Niveau pellets',
            'L_storage_min'       => 'Seuil alerte pellets',
            'L_storage_max'       => 'Capacité stockage',
            'L_storage_hopper'    => 'Pellets trémie',
            'L_pellets_today'     => 'Conso. aujourd\'hui',
            'L_pellets_yesterday' => 'Conso. hier',
            // Statistiques
            'L_starts'            => 'Nb démarrages brûleur',
            'L_runtime'           => 'Durée totale fonctionnement',
            'L_avg_runtime'       => 'Durée moy. fonctionnement',
            // Réglages
            'mode'                => 'Mode',
            'suction_clean_time'  => 'Heure aspiration',
            'suction_sec_time'    => 'Heure aspiration 2',
        ],
        'bounds' => [
            'L_temp_act' => 'water_temp',
            'L_temp_set' => 'water_temp',
            // L_ext_temp (fumées) et L_frt_temp_* (flamme) montent haut → laissés sans bornes
        ],
    ],
    'system' => [
        'standard' => [
            'L_ambient', 'L_boiler_temp', 'mode',
        ],
        'names' => [
            'L_ambient'         => 'Temp. extérieure',
            'L_errors'          => 'Défauts',
            'L_usb_stick'       => 'USB connectée',
            'L_boiler_temp'     => 'Temp. chaudière',
            'L_existing_boiler' => 'Temp. mesurée',
            'mode'              => 'Mode',
        ],
        'bounds' => [
            'L_ambient'         => 'ext_temp',
            'L_boiler_temp'     => 'water_temp',
            'L_existing_boiler' => 'water_temp',
        ],
    ],
    'weather' => [
        'standard' => ['L_temp', 'L_forecast_temp'],
        'names' => [
            'L_temp'            => 'Temp. actuelle',
            'L_clouds'          => 'Couverture nuageuse',
            'L_forecast_temp'   => 'Temp. prévue',
            'L_forecast_clouds' => 'Nébulosité prévue',
            'L_forecast_today'  => 'Début prévisions',
            'L_starttime'       => 'Heure début',
            'L_endtime'         => 'Heure fin',
            'L_source'          => 'Source météo',
            'L_location'        => 'Localité',
            'cloud_limit'       => 'Seuil nuages',
            'hysteresys'        => 'Hystérésis',
            'offtemp'           => 'Temp. coupure ext.',
            'lead'              => 'Anticipation',
            'oekomode'          => 'Mode écolo',
        ],
        'bounds' => [
            'L_temp'          => 'ext_temp',
            'L_forecast_temp' => 'ext_temp',
        ],
    ],
    'circ' => [
        // Note : pas de L_state ni mode_auto dans circ — mode = 'mode' (0:Arrêt|1:Auto)
        'standard' => ['L_pump', 'L_ret_temp', 'mode'],
        'names' => [
            'L_pump'         => 'Pompe',
            'L_ret_temp'     => 'Temp. retour',
            'L_release_temp' => 'Temp. marche',
            'time_prg'       => 'Choix programme',
            'mode'           => 'Mode',
            'pump_release'   => 'Temp. déclenchement pompe',
            'return_set'     => 'Temp. coupure retour',
            'name'           => 'Nom du circuit',
        ],
        'bounds' => [
            'L_ret_temp' => 'water_temp',
        ],
    ],
    'pu' => [
        'standard' => ['L_temp_act', 'L_state'],
        'names' => [
            'L_temp_act' => 'Temp. accumulateur',
            'L_state'    => 'État',
        ],
        'bounds' => [
            'L_temp_act' => 'water_temp',
        ],
    ],
    'sk' => [
        'standard' => ['L_temp_act', 'L_state'],
        'names' => [
            'L_temp_act' => 'Temp. capteur solaire',
            'L_state'    => 'État',
        ],
        'bounds' => [
            'L_temp_act' => 'water_temp',
        ],
    ],
    // Types sans config : toutes les variables exposées (comme mode expert)
];
