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
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();
    
    log::add('hon', 'debug', '=== AJAX ACTION: ' . init('action') . ' ===');

    if (init('action') == 'testConnection') {
        log::add('hon', 'info', '=== DÉBUT TEST CONNEXION ===');
        
        $email = init('email');
        $password = init('password');

        log::add('hon', 'info', 'Email reçu: ' . $email);
        log::add('hon', 'info', 'Password longueur: ' . strlen($password) . ' caractères');

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Email ou password manquant');
            throw new Exception(__('Email et mot de passe requis', __FILE__));
        }

        // Vérifier l'existence du fichier Python
        $pythonFile = dirname(__FILE__) . '/../../resources/hon_get_tokens.py';
        log::add('hon', 'info', 'Chemin fichier Python: ' . $pythonFile);

        if (!file_exists($pythonFile)) {
            throw new Exception(__('Fichier hon_get_tokens.py introuvable: ', __FILE__) . $pythonFile);
        }

        // Test de connexion via le script Python
        $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_get_tokens.py ' . 
               escapeshellarg($email) . ' ' . escapeshellarg($password);

        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'info', 'Sortie brute du script Python: ' . $output);

        $tokens = json_decode($output, true);
        
        if (!is_array($tokens) || !isset($tokens['id_token']) || !isset($tokens['cognito_token'])) {
            log::add('hon', 'error', 'Format de tokens invalide');
            throw new Exception(__('Échec de l\'authentification : ', __FILE__) . $output);
        }

        log::add('hon', 'info', '=== FIN TEST CONNEXION - SUCCÈS ===');
        ajax::success('Connexion réussie ! Tokens récupérés avec succès.');
    }

    if (init('action') == 'syncDevices') {
        log::add('hon', 'info', '=== DÉBUT SYNCHRONISATION COMPLÈTE ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        log::add('hon', 'info', 'Email config: ' . $email);
        log::add('hon', 'info', 'Password config longueur: ' . strlen($password) . ' caractères');

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Configuration email/password manquante');
            throw new Exception(__('Email et mot de passe non configurés dans les paramètres du plugin', __FILE__));
        }

        // Inclure la classe hon
        require_once dirname(__FILE__) . '/../class/hon.class.php';

        // Lancer la synchronisation complète (génération JSONs + création équipements)
        $result = hon::syncDevices();
        log::add('hon', 'info', 'Résultat synchronisation: ' . ($result ? 'SUCCÈS' : 'ÉCHEC'));

        if (!$result) {
            throw new Exception(__('Échec de la synchronisation des équipements', __FILE__));
        }

        // Compter les équipements créés/mis à jour
        $equipments = eqLogic::byType('hon');
        $count = count($equipments);
        log::add('hon', 'info', 'Nombre équipements trouvés: ' . $count);

        // Vérifier les fichiers JSON générés
        $dataDir = dirname(__FILE__) . '/../../data';
        $programFiles = glob($dataDir . '/*_*.json');
        $deviceFiles = glob($dataDir . '/device_*.json');
        
        log::add('hon', 'info', 'Fichiers JSON générés: ' . count($programFiles) . ' programmes, ' . count($deviceFiles) . ' appareils');

        log::add('hon', 'info', '=== FIN SYNCHRONISATION - SUCCÈS ===');
        ajax::success("Synchronisation réussie ! $count équipements trouvés. " . count($programFiles) . " programmes et " . count($deviceFiles) . " appareils détectés.");
    }

    if (init('action') == 'syncEqLogic') {
        // Alias pour syncDevices (compatibilité interface Jeedom)
        log::add('hon', 'info', '=== REDIRECTION syncEqLogic vers syncDevices ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        if (empty($email) || empty($password)) {
            throw new Exception(__('Email et mot de passe non configurés', __FILE__));
        }

        require_once dirname(__FILE__) . '/../class/hon.class.php';
        $result = hon::syncDevices();

        if (!$result) {
            throw new Exception(__('Échec de la synchronisation des équipements', __FILE__));
        }

        $equipments = eqLogic::byType('hon');
        $count = count($equipments);

        ajax::success("Synchronisation réussie ! $count équipements trouvés.");
    }

    if (init('action') == 'getTokenStatus') {
        log::add('hon', 'info', '=== DÉBUT STATUT TOKENS ===');
        
        require_once dirname(__FILE__) . '/../class/hon.class.php';
        
        // Récupérer le statut des tokens
        $equipments = eqLogic::byType('hon');
        
        if (empty($equipments)) {
            $tempHon = new hon();
            $status = $tempHon->getTokenStatus();
        } else {
            $status = $equipments[0]->getTokenStatus();
        }

        log::add('hon', 'info', 'Statut tokens récupéré: ' . json_encode($status));
        log::add('hon', 'info', '=== FIN STATUT TOKENS ===');

        ajax::success($status);
    }

    if (init('action') == 'refreshTokens') {
        log::add('hon', 'info', '=== DÉBUT REFRESH TOKENS ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Configuration email/password manquante pour refresh');
            throw new Exception(__('Email et mot de passe non configurés', __FILE__));
        }

        require_once dirname(__FILE__) . '/../class/hon.class.php';

        $result = hon::refreshTokens();
        log::add('hon', 'info', 'Résultat refresh tokens: ' . ($result ? 'SUCCÈS' : 'ÉCHEC'));

        if (!$result) {
            throw new Exception(__('Échec de la régénération des tokens', __FILE__));
        }

        log::add('hon', 'info', '=== FIN REFRESH TOKENS - SUCCÈS ===');
        ajax::success('Tokens régénérés avec succès !');
    }

    if (init('action') == 'refreshInfo') {
        log::add('hon', 'info', '=== DÉBUT REFRESH INFO ===');
        
        $eqLogic_id = init('id');
        log::add('hon', 'info', 'Équipement ID: ' . $eqLogic_id);
        
        if (empty($eqLogic_id)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            log::add('hon', 'error', 'Équipement introuvable: ' . $eqLogic_id);
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        if ($eqLogic->getEqType_name() != 'hon') {
            log::add('hon', 'error', 'Équipement pas de type hOn: ' . $eqLogic->getEqType_name());
            throw new Exception(__('Équipement non hOn', __FILE__));
        }

        log::add('hon', 'info', 'Équipement trouvé: ' . $eqLogic->getName());

        // Rafraîchir les informations
        $eqLogic->refreshInfo();

        log::add('hon', 'info', '=== FIN REFRESH INFO - SUCCÈS ===');
        ajax::success('Informations rafraîchies avec succès !');
    }

    if (init('action') == 'getDeviceInfo') {
        log::add('hon', 'info', '=== DÉBUT GET DEVICE INFO ===');
        
        $eqLogic_id = init('id');
        log::add('hon', 'info', 'Équipement ID: ' . $eqLogic_id);
        
        if (empty($eqLogic_id)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        if ($eqLogic->getEqType_name() != 'hon') {
            throw new Exception(__('Équipement non hOn', __FILE__));
        }

        $deviceInfo = [
            'name' => $eqLogic->getName(),
            'macAddress' => $eqLogic->getConfiguration('macAddress', 'N/A'),
            'mac_filename' => $eqLogic->getConfiguration('mac_filename', 'N/A'),
            'applianceType' => $eqLogic->getConfiguration('applianceType', 'N/A'),
            'programsCount' => $eqLogic->getConfiguration('programsCount', '0'),
            'settingsCount' => $eqLogic->getConfiguration('settingsCount', '0'),
            'lastSync' => $eqLogic->getConfiguration('lastSync', 'Jamais'),
            'isEnable' => $eqLogic->getIsEnable(),
            'isVisible' => $eqLogic->getIsVisible()
        ];

        log::add('hon', 'info', 'Info équipement récupérée: ' . $eqLogic->getName());
        log::add('hon', 'info', '=== FIN GET DEVICE INFO - SUCCÈS ===');

        ajax::success($deviceInfo);
    }

    if (init('action') == 'launchProgram') {
        log::add('hon', 'info', '=== DÉBUT LANCEMENT PROGRAMME ===');
        
        $eqLogic_id = init('id');
        $programName = init('program');
        $parameters = init('parameters', []);
        
        log::add('hon', 'info', 'Équipement ID: ' . $eqLogic_id);
        log::add('hon', 'info', 'Programme: ' . $programName);
        log::add('hon', 'info', 'Paramètres: ' . json_encode($parameters));
        
        if (empty($eqLogic_id) || empty($programName)) {
            throw new Exception(__('Paramètres manquants', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        if ($eqLogic->getEqType_name() != 'hon') {
            throw new Exception(__('Équipement non hOn', __FILE__));
        }

        log::add('hon', 'info', 'Équipement trouvé: ' . $eqLogic->getName());

        require_once dirname(__FILE__) . '/../class/hon.class.php';

        // Lancer le programme via la méthode de la classe
        $result = $eqLogic->launchProgram($programName, $parameters);

        if (!$result) {
            throw new Exception(__('Échec du lancement du programme', __FILE__));
        }

        log::add('hon', 'info', '=== FIN LANCEMENT PROGRAMME - SUCCÈS ===');
        ajax::success('Programme ' . $programName . ' lancé avec succès !');
    }

    if (init('action') == 'executeAction') {
        log::add('hon', 'info', '=== DÉBUT EXECUTE ACTION ===');
        
        $eqLogic_id = init('id');
        $action = init('actionType');
        
        log::add('hon', 'info', 'Équipement ID: ' . $eqLogic_id);
        log::add('hon', 'info', 'Action demandée: ' . $action);
        
        if (empty($eqLogic_id) || empty($action)) {
            throw new Exception(__('Paramètres manquants', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        if ($eqLogic->getEqType_name() != 'hon') {
            throw new Exception(__('Équipement non hOn', __FILE__));
        }

        log::add('hon', 'info', 'Équipement trouvé: ' . $eqLogic->getName());

        require_once dirname(__FILE__) . '/../class/hon.class.php';

        switch ($action) {
            case 'stop':
                log::add('hon', 'info', 'Action STOP demandée');
                $result = $eqLogic->launchProgram('stop');
                if (!$result) {
                    throw new Exception(__('Échec de l\'arrêt', __FILE__));
                }
                ajax::success('Commande d\'arrêt envoyée avec succès !');
                break;

            case 'startProgram':
                log::add('hon', 'info', 'Action START PROGRAM demandée');
                
                $programName = init('programName', 'cottons');
                $parameters = [];
                
                // Récupérer les paramètres selon le type de programme
                if (init('temp')) $parameters['temp'] = init('temp');
                if (init('spinSpeed')) $parameters['spinSpeed'] = init('spinSpeed');
                if (init('mainWashTime')) $parameters['mainWashTime'] = init('mainWashTime');
                if (init('rinseIterations')) $parameters['rinseIterations'] = init('rinseIterations');
                
                log::add('hon', 'info', "Lancement programme: $programName avec paramètres: " . json_encode($parameters));

                $result = $eqLogic->launchProgram($programName, $parameters);
                if (!$result) {
                    throw new Exception(__('Échec du lancement du programme', __FILE__));
                }
                
                ajax::success('Programme ' . $programName . ' lancé avec succès !');
                break;

            default:
                log::add('hon', 'error', 'Action inconnue: ' . $action);
                throw new Exception(__('Action inconnue : ', __FILE__) . $action);
        }

        log::add('hon', 'info', '=== FIN EXECUTE ACTION - SUCCÈS ===');
    }

    if (init('action') == 'getDeviceStatus') {
        log::add('hon', 'info', '=== DÉBUT GET DEVICE STATUS ===');
        
        $eqLogic_id = init('id');
        log::add('hon', 'info', 'Équipement ID: ' . $eqLogic_id);
        
        if (empty($eqLogic_id)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        $macAddress = $eqLogic->getConfiguration('macAddress');
        if (empty($macAddress)) {
            throw new Exception(__('Adresse MAC manquante', __FILE__));
        }

        log::add('hon', 'info', 'MAC Address: ' . $macAddress);

        require_once dirname(__FILE__) . '/../class/hon.class.php';

        // Récupérer le statut depuis le fichier JSON de l'appareil
        $dataDir = dirname(__FILE__) . '/../../data';
        $macFilename = str_replace(':', '-', $macAddress);
        $deviceFile = $dataDir . '/device_' . $macFilename . '.json';
        
        if (!file_exists($deviceFile)) {
            throw new Exception(__('Fichier d\'information de l\'appareil non trouvé', __FILE__));
        }

        $content = file_get_contents($deviceFile);
        $deviceData = json_decode($content, true);
        
        if (!$deviceData) {
            throw new Exception(__('Format de données invalide', __FILE__));
        }

        // Extraire les informations de statut pertinentes
        $statusData = [
            'device_name' => $deviceData['device_identification']['extracted_info']['name'] ?? 'N/A',
            'connection_status' => $deviceData['connectivity']['connection_status'] ?? 'unknown',
            'last_connected' => $deviceData['connectivity']['last_connected'] ?? [],
            'commands_count' => $deviceData['commands']['filtered_commands_count'] ?? 0,
            'extraction_time' => $deviceData['extraction_info']['extracted_at'] ?? 'N/A'
        ];

        log::add('hon', 'info', 'Statut récupéré avec succès');
        log::add('hon', 'info', '=== FIN GET DEVICE STATUS - SUCCÈS ===');

        ajax::success($statusData);
    }

    if (init('action') == 'getAvailablePrograms') {
        log::add('hon', 'info', '=== DÉBUT GET AVAILABLE PROGRAMS ===');
        
        $eqLogic_id = init('id');
        
        if (empty($eqLogic_id)) {
            throw new Exception(__('ID équipement manquant', __FILE__));
        }

        $eqLogic = eqLogic::byId($eqLogic_id);
        
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }

        $macAddress = $eqLogic->getConfiguration('macAddress');
        $macFilename = $eqLogic->getConfiguration('mac_filename');
        
        if (empty($macAddress) || empty($macFilename)) {
            throw new Exception(__('Informations MAC manquantes', __FILE__));
        }

        // Rechercher tous les programmes disponibles pour cet appareil
        $dataDir = dirname(__FILE__) . '/../../data';
        $pattern = $dataDir . '/' . $macFilename . '_*.json';
        $programFiles = glob($pattern);
        
        $programs = [];
        
        foreach ($programFiles as $file) {
            $content = file_get_contents($file);
            $programData = json_decode($content, true);
            
            if ($programData && isset($programData['program_info'])) {
                $programInfo = $programData['program_info'];
                $programs[] = [
                    'name' => $programInfo['name'],
                    'display_name' => $programInfo['display_name'],
                    'description' => $programInfo['description'] ?? '',
                    'configurable_parameters' => $programInfo['configurable_parameters'] ?? [],
                    'default_parameters' => $programInfo['default_parameters'] ?? []
                ];
            }
        }

        log::add('hon', 'info', count($programs) . ' programmes trouvés pour ' . $eqLogic->getName());
        log::add('hon', 'info', '=== FIN GET AVAILABLE PROGRAMS ===');

        ajax::success($programs);
    }

    if (init('action') == 'regenerateJsons') {
        log::add('hon', 'info', '=== DÉBUT RÉGÉNÉRATION JSONs ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        if (empty($email) || empty($password)) {
            throw new Exception(__('Email et mot de passe non configurés', __FILE__));
        }

        require_once dirname(__FILE__) . '/../class/hon.class.php';

        // Appeler la méthode de génération des JSONs
        $result = hon::generateDeviceJsons($email, $password);
        
        if (!$result) {
            throw new Exception(__('Échec de la génération des fichiers JSON', __FILE__));
        }

        // Compter les fichiers générés
        $dataDir = dirname(__FILE__) . '/../../data';
        $programFiles = glob($dataDir . '/*_*.json');
        $deviceFiles = glob($dataDir . '/device_*.json');
        
        log::add('hon', 'info', 'JSONs régénérés: ' . count($programFiles) . ' programmes, ' . count($deviceFiles) . ' appareils');
        log::add('hon', 'info', '=== FIN RÉGÉNÉRATION JSONs - SUCCÈS ===');

        ajax::success('Fichiers JSON régénérés avec succès ! ' . count($programFiles) . ' programmes et ' . count($deviceFiles) . ' appareils détectés.');
    }

    log::add('hon', 'error', 'Action AJAX inconnue: ' . init('action'));
    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Exception $e) {
    log::add('hon', 'error', 'ERREUR AJAX: ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
?>