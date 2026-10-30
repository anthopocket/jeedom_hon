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
        require_once dirname(__FILE__) . '/../class/hon.class.php';
        $cmd = 'cd ' . escapeshellarg(dirname(__FILE__) . '/../../resources') . ' && ' . hon::python() . ' hon_get_tokens.py ' . 
               escapeshellarg($email) . ' ' . escapeshellarg($password);

        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie brute du script Python: ' . $output);

        $tokens = json_decode($output, true);

        if (!is_array($tokens) || !isset($tokens['id_token']) || !isset($tokens['cognito_token'])) {
            // Détail technique dans le log, message court à l'écran
            log::add('hon', 'warning', 'Connexion hOn refusée pour ' . $email . ' (détail en debug)');
            ajax::error(__('Identifiant ou mot de passe incorrect', __FILE__));
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

        // Compter les fichiers JSON générés
        $dataDir = config::byKey('dataDir', 'hon', dirname(__FILE__) . '/../../resources/hon/data');
        $deviceFiles = glob($dataDir . '/device_*.json') ?: [];
        $programFiles = array_filter(glob($dataDir . '/*_*.json') ?: [], function ($f) {
            return strpos(basename($f), 'device_') !== 0 && strpos(basename($f), 'hon_devices') !== 0;
        });
        
        log::add('hon', 'info', 'Fichiers JSON générés: ' . count($programFiles) . ' programmes, ' . count($deviceFiles) . ' appareils');

        log::add('hon', 'info', '=== FIN SYNCHRONISATION - SUCCÈS ===');
        ajax::success("Synchronisation réussie ! $count équipements trouvés. " . count($programFiles) . " programmes et " . count($deviceFiles) . " appareils détectés.");
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


    log::add('hon', 'error', 'Action AJAX inconnue: ' . init('action'));
    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Exception $e) {
    log::add('hon', 'error', 'ERREUR AJAX: ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
?>