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

        // Logs de debug pour vérifier les données reçues
        log::add('hon', 'info', 'Email reçu: ' . $email);
        log::add('hon', 'info', 'Password longueur: ' . strlen($password) . ' caractères');
        log::add('hon', 'info', 'Password commence par: ' . substr($password, 0, 3) . '...');
        log::add('hon', 'info', 'Email vide: ' . (empty($email) ? 'OUI' : 'NON'));
        log::add('hon', 'info', 'Password vide: ' . (empty($password) ? 'OUI' : 'NON'));

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Email ou password manquant');
            throw new Exception(__('Email et mot de passe requis', __FILE__));
        }

        // Vérifier l'existence du fichier Python
        $pythonFile = dirname(__FILE__) . '/../../resources/hon_get_tokens.py';
        log::add('hon', 'info', 'Chemin fichier Python: ' . $pythonFile);
        log::add('hon', 'info', 'Fichier Python existe: ' . (file_exists($pythonFile) ? 'OUI' : 'NON'));

        if (!file_exists($pythonFile)) {
            throw new Exception(__('Fichier hon_get_tokens.py introuvable: ', __FILE__) . $pythonFile);
        }

        // Test de connexion via le script Python
        $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_get_tokens.py ' . 
               escapeshellarg($email) . ' ' . escapeshellarg($password);

        log::add('hon', 'info', 'Commande exécutée: ' . $cmd);
        log::add('hon', 'info', 'Email échappé: ' . escapeshellarg($email));
        log::add('hon', 'info', 'Password échappé: ' . escapeshellarg($password));

        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'info', 'Sortie brute du script Python: ' . $output);

        // Essayer de décoder le JSON
        $tokens = json_decode($output, true);
        log::add('hon', 'info', 'Décodage JSON réussi: ' . (is_array($tokens) ? 'OUI' : 'NON'));
        
        if (is_array($tokens)) {
            log::add('hon', 'info', 'Clés trouvées dans JSON: ' . implode(', ', array_keys($tokens)));
            log::add('hon', 'info', 'id_token présent: ' . (isset($tokens['id_token']) ? 'OUI' : 'NON'));
            log::add('hon', 'info', 'cognito_token présent: ' . (isset($tokens['cognito_token']) ? 'OUI' : 'NON'));
        } else {
            log::add('hon', 'error', 'Erreur décodage JSON: ' . json_last_error_msg());
        }

        if (!is_array($tokens) || !isset($tokens['id_token']) || !isset($tokens['cognito_token'])) {
            log::add('hon', 'error', 'Format de tokens invalide');
            throw new Exception(__('Échec de l\'authentification : ', __FILE__) . $output);
        }

        log::add('hon', 'info', '=== FIN TEST CONNEXION - SUCCÈS ===');
        ajax::success('Connexion réussie ! Tokens récupérés avec succès.');
    }

    if (init('action') == 'syncDevices') {
        log::add('hon', 'info', '=== DÉBUT SYNCHRONISATION ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        log::add('hon', 'info', 'Email config: ' . $email);
        log::add('hon', 'info', 'Password config longueur: ' . strlen($password) . ' caractères');

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Configuration email/password manquante pour refresh');
            throw new Exception(__('Email et mot de passe non configurés dans les paramètres du plugin', __FILE__));
        }

        // Inclure la classe hon
        require_once dirname(__FILE__) . '/../class/hon.class.php';

        $result = hon::syncDevices();
        log::add('hon', 'info', 'Résultat synchronisation: ' . ($result ? 'SUCCÈS' : 'ÉCHEC'));

        if (!$result) {
            throw new Exception(__('Échec de la synchronisation des équipements', __FILE__));
        }

        // Compter les équipements créés/mis à jour
        $equipments = eqLogic::byType('hon');
        $count = count($equipments);
        log::add('hon', 'info', 'Nombre équipements trouvés: ' . $count);

        log::add('hon', 'info', '=== FIN SYNCHRONISATION - SUCCÈS ===');
        ajax::success("Synchronisation réussie ! $count équipements trouvés.");
    }

    if (init('action') == 'syncEqLogic') {
        // Alias pour syncDevices (compatibilité interface Jeedom)
        log::add('hon', 'info', '=== REDIRECTION syncEqLogic vers syncDevices ===');
        
        $email = config::byKey('email', 'hon');
        $password = config::byKey('password', 'hon');

        if (empty($email) || empty($password)) {
            throw new Exception(__('Email et mot de passe non configurés', __FILE__));
        }

        // Inclure la classe hon
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
        
        // Inclure la classe hon
        require_once dirname(__FILE__) . '/../class/hon.class.php';
        
        // Récupérer le statut des tokens depuis n'importe quel équipement hOn
        $equipments = eqLogic::byType('hon');
        
        if (empty($equipments)) {
            // Si pas d'équipement, créer une instance temporaire
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

        log::add('hon', 'info', 'Email config: ' . $email);
        log::add('hon', 'info', 'Password config longueur: ' . strlen($password) . ' caractères');

        if (empty($email) || empty($password)) {
            log::add('hon', 'error', 'Configuration email/password manquante pour refresh');
            throw new Exception(__('Email et mot de passe non configurés', __FILE__));
        }

        // Inclure la classe hon
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

        // Note: refreshInfo sera implémentée plus tard
        // $eqLogic->refreshInfo();

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
            'applianceTypeName' => $eqLogic->getConfiguration('applianceTypeName', 'N/A'),
            'applianceTypeId' => $eqLogic->getConfiguration('applianceTypeId', 'N/A'),
            'brand' => $eqLogic->getConfiguration('brand', 'N/A'),
            'modelName' => $eqLogic->getConfiguration('modelName', 'N/A'),
            'fwVersion' => $eqLogic->getConfiguration('fwVersion', 'N/A'),
            'lastSync' => $eqLogic->getConfiguration('lastSync', 'Jamais'),
            'isEnable' => $eqLogic->getIsEnable(),
            'isVisible' => $eqLogic->getIsVisible()
        ];

        log::add('hon', 'info', 'Info équipement récupérée: ' . $eqLogic->getName());
        log::add('hon', 'info', '=== FIN GET DEVICE INFO - SUCCÈS ===');

        ajax::success($deviceInfo);
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

        // Inclure la classe hon
        require_once dirname(__FILE__) . '/../class/hon.class.php';

        switch ($action) {
            case 'stop':
                log::add('hon', 'info', 'Action STOP demandée');
                
                $macAddress = $eqLogic->getConfiguration('macAddress');
                if (empty($macAddress)) {
                    throw new Exception(__('Adresse MAC manquante', __FILE__));
                }

                $tokens = hon::getCachedTokens();
                if (!$tokens) {
                    throw new Exception(__('Impossible de récupérer les tokens', __FILE__));
                }

                log::add('hon', 'info', 'MAC: ' . $macAddress);
                log::add('hon', 'info', 'Tokens récupérés pour action');

                $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_wash_dryer_actions.py ' . 
                       escapeshellarg($tokens['id_token']) . ' ' . 
                       escapeshellarg($tokens['cognito_token']) . ' turn_off ' . 
                       escapeshellarg($macAddress);

                log::add('hon', 'info', 'Commande STOP: ' . $cmd);

                $output = shell_exec($cmd . ' 2>&1');
                log::add('hon', 'info', 'Sortie commande STOP: ' . $output);

                ajax::success('Commande d\'arrêt envoyée avec succès !');
                break;

            case 'startWash':
                log::add('hon', 'info', 'Action START WASH demandée');
                
                $macAddress = $eqLogic->getConfiguration('macAddress');
                if (empty($macAddress)) {
                    throw new Exception(__('Adresse MAC manquante', __FILE__));
                }

                $tokens = hon::getCachedTokens();
                if (!$tokens) {
                    throw new Exception(__('Impossible de récupérer les tokens', __FILE__));
                }

                $temp = init('temp', '30');
                $rinse = init('rinse', '2');
                $spin = init('spin', '800');
                $time = init('time', '15');

                log::add('hon', 'info', "Paramètres lavage - Temp: $temp, Rinse: $rinse, Spin: $spin, Time: $time");

                $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_wash_dryer_actions.py ' . 
                       escapeshellarg($tokens['id_token']) . ' ' . 
                       escapeshellarg($tokens['cognito_token']) . ' start_wash ' . 
                       escapeshellarg($macAddress) . ' ' . 
                       escapeshellarg($temp) . ' ' . 
                       escapeshellarg($rinse) . ' ' . 
                       escapeshellarg($spin) . ' ' . 
                       escapeshellarg($time);

                log::add('hon', 'info', 'Commande START WASH: ' . $cmd);

                $output = shell_exec($cmd . ' 2>&1');
                log::add('hon', 'info', 'Sortie commande START WASH: ' . $output);

                ajax::success('Commande de démarrage lavage envoyée avec succès !');
                break;

            case 'startDry':
                log::add('hon', 'info', 'Action START DRY demandée');
                
                $macAddress = $eqLogic->getConfiguration('macAddress');
                if (empty($macAddress)) {
                    throw new Exception(__('Adresse MAC manquante', __FILE__));
                }

                $tokens = hon::getCachedTokens();
                if (!$tokens) {
                    throw new Exception(__('Impossible de récupérer les tokens', __FILE__));
                }

                $tempLevel = init('tempLevel', '3');
                $dryLevel = init('dryLevel', '3');
                $antiCrease = init('antiCrease', '120');

                log::add('hon', 'info', "Paramètres séchage - TempLevel: $tempLevel, DryLevel: $dryLevel, AntiCrease: $antiCrease");

                $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_wash_dryer_actions.py ' . 
                       escapeshellarg($tokens['id_token']) . ' ' . 
                       escapeshellarg($tokens['cognito_token']) . ' start_dryer ' . 
                       escapeshellarg($macAddress) . ' ' . 
                       escapeshellarg($tempLevel) . ' ' . 
                       escapeshellarg($dryLevel) . ' ' . 
                       escapeshellarg($antiCrease);

                log::add('hon', 'info', 'Commande START DRY: ' . $cmd);

                $output = shell_exec($cmd . ' 2>&1');
                log::add('hon', 'info', 'Sortie commande START DRY: ' . $output);

                ajax::success('Commande de démarrage séchage envoyée avec succès !');
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

        // Inclure la classe hon
        require_once dirname(__FILE__) . '/../class/hon.class.php';

        $tokens = hon::getCachedTokens();
        if (!$tokens) {
            throw new Exception(__('Impossible de récupérer les tokens', __FILE__));
        }

        log::add('hon', 'info', 'Tokens récupérés pour statut');

        // Récupérer le statut via le script Python
        $cmd = 'cd ' . dirname(__FILE__) . '/../../resources && python3 hon_wash_dryer_status.py ' . 
               escapeshellarg($tokens['id_token']) . ' ' . 
               escapeshellarg($tokens['cognito_token']) . ' ' . 
               escapeshellarg($macAddress);

        log::add('hon', 'info', 'Commande statut: ' . $cmd);

        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'info', 'Sortie brute statut: ' . $output);
        
        // Extraire le JSON
        $lines = explode("\n", $output);
        $jsonOutput = '';
        $jsonFound = false;
        
        foreach ($lines as $line) {
            if (trim($line) == '{' || $jsonFound) {
                $jsonOutput .= $line . "\n";
                $jsonFound = true;
            }
        }

        log::add('hon', 'info', 'JSON extrait: ' . $jsonOutput);

        $statusData = json_decode(trim($jsonOutput), true);
        
        if (!is_array($statusData)) {
            log::add('hon', 'error', 'Format données statut invalide');
            throw new Exception(__('Format de données invalide : ', __FILE__) . $output);
        }

        log::add('hon', 'info', 'Statut récupéré avec succès');
        log::add('hon', 'info', '=== FIN GET DEVICE STATUS - SUCCÈS ===');

        ajax::success($statusData);
    }

    log::add('hon', 'error', 'Action AJAX inconnue: ' . init('action'));
    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Exception $e) {
    log::add('hon', 'error', 'ERREUR AJAX: ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
?>