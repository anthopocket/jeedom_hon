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

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class hon extends eqLogic {
    /*     * *************************Attributs****************************** */

    /*     * ***********************Méthodes statiques*************************** */

    /**
     * Fonction exécutée automatiquement toutes les minutes par Jeedom
     */
    public static function cron() {
        // Vérifier l'expiration des tokens toutes les minutes
        self::checkTokenExpiration();
        
        // Rafraîchir les données des équipements toutes les minutes
        self::refreshAllDevices();
    }

    /**
     * Fonction exécutée automatiquement toutes les heures par Jeedom
     */
    public static function cronHourly() {
        self::syncDevices();
    }

    /**
     * Rafraîchit les données de tous les équipements hon
     */
    public static function refreshAllDevices() {
        try {
            $eqLogics = eqLogic::byType('hon', true);
            
            if (!is_array($eqLogics) || empty($eqLogics)) {
                return;
            }
            
            foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getIsEnable()) {
                    // Vérifier si le dernier rafraîchissement date de plus d'1 minute
                    $lastRefresh = (int)$eqLogic->getConfiguration('lastRefresh', 0);
                    $currentTime = time();
                    
                    if (($currentTime - $lastRefresh) >= 60) { // 60 secondes = 1 minute
                        $eqLogic->refreshInfo();
                    }
                }
            }
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur dans refreshAllDevices : ' . $e->getMessage());
        }
    }

    /**
     * Vérifie l'expiration des tokens et les régénère si nécessaire
     */
    public static function checkTokenExpiration() {
        $lastTokenTime = config::byKey('lastTokenTime', 'hon', 0);
        $currentTime = time();
        
        // Régénérer les tokens toutes les 6 heures (21600 secondes)
        // On régénère à 5h30 pour être sûr (19800 secondes)
        if (($currentTime - $lastTokenTime) > 19800) {
            log::add('hon', 'info', 'Tokens expirés, régénération automatique...');
            self::refreshTokens();
        }
    }

    /**
     * Force la régénération des tokens
     */
    public static function refreshTokens() {
        try {
            $email = config::byKey('email', 'hon');
            $password = config::byKey('password', 'hon');
            
            if (empty($email) || empty($password)) {
                log::add('hon', 'error', 'Email ou mot de passe manquant pour régénérer les tokens');
                return false;
            }
            
            // Générer de nouveaux tokens
            $tokens = self::getTokens($email, $password);
            if (!$tokens) {
                log::add('hon', 'error', 'Impossible de régénérer les tokens hOn');
                return false;
            }
            
            // Sauvegarder les nouveaux tokens et l'horodatage
            config::save('cachedIdToken', $tokens['id_token'], 'hon');
            config::save('cachedCognitoToken', $tokens['cognito_token'], 'hon');
            config::save('cachedMobileId', $tokens['mobile_id'], 'hon');
            config::save('lastTokenTime', time(), 'hon');
            
            log::add('hon', 'info', 'Tokens régénérés avec succès');
            return true;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors de la régénération des tokens : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Synchronise les équipements depuis l'API hOn
     */
    public static function syncDevices() {
        log::add('hon', 'info', 'Début de la synchronisation des équipements hOn');
        
        try {
            // Récupération des identifiants de configuration
            $email = config::byKey('email', 'hon');
            $password = config::byKey('password', 'hon');
            
            if (empty($email) || empty($password)) {
                log::add('hon', 'error', 'Email ou mot de passe manquant dans la configuration');
                return false;
            }
            
            // Récupération des tokens (avec cache)
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'error', 'Impossible de récupérer les tokens hOn');
                return false;
            }
            
            // Récupération des équipements
            $devices = self::getDevices($tokens['id_token'], $tokens['cognito_token']);
            if (!$devices) {
                log::add('hon', 'error', 'Impossible de récupérer la liste des équipements');
                return false;
            }
            
            // Filtrer uniquement les machines à laver et sèche-linge
            $supportedTypes = ['WASHING_MACHINE', 'WM', 'TUMBLE_DRYER', 'TD'];
            $filteredDevices = [];
            
            if (isset($devices['appliances']) && is_array($devices['appliances'])) {
                foreach ($devices['appliances'] as $device) {
                    $deviceType = strtoupper($device['applianceTypeName'] ?? '');
                    $deviceTypeId = strtoupper($device['applianceTypeId'] ?? '');
                    
                    if (in_array($deviceType, $supportedTypes) || in_array($deviceTypeId, $supportedTypes)) {
                        $filteredDevices[] = $device;
                        log::add('hon', 'info', "✅ Équipement compatible: $deviceType - " . ($device['nickName'] ?? 'N/A'));
                    }
                }
            }
            
            log::add('hon', 'info', count($filteredDevices) . ' équipements compatibles trouvés');
            
            // Création/mise à jour des équipements
            foreach ($filteredDevices as $deviceData) {
                self::createOrUpdateDevice($deviceData, $tokens);
            }
            
            log::add('hon', 'info', 'Synchronisation terminée avec succès');
            return true;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors de la synchronisation : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère les tokens (avec cache ou régénération automatique)
     */
    public static function getCachedTokens() {
        try {
            $lastTokenTime = (int)config::byKey('lastTokenTime', 'hon', 0);
            $currentTime = time();
            
            // Vérifier si les tokens sont encore valides (moins de 5h30)
            if (($currentTime - $lastTokenTime) < 19800) {
                $cachedIdToken = config::byKey('cachedIdToken', 'hon');
                $cachedCognitoToken = config::byKey('cachedCognitoToken', 'hon');
                $cachedMobileId = config::byKey('cachedMobileId', 'hon');
                
                if (!empty($cachedIdToken) && !empty($cachedCognitoToken)) {
                    return [
                        'id_token' => $cachedIdToken,
                        'cognito_token' => $cachedCognitoToken,
                        'mobile_id' => $cachedMobileId
                    ];
                }
            }
            
            // Tokens expirés ou inexistants, régénération
            if (self::refreshTokens()) {
                return [
                    'id_token' => config::byKey('cachedIdToken', 'hon'),
                    'cognito_token' => config::byKey('cachedCognitoToken', 'hon'),
                    'mobile_id' => config::byKey('cachedMobileId', 'hon')
                ];
            }
            
            return false;
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getCachedTokens : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère les tokens d'authentification
     */
    private static function getTokens($email, $password) {
        $cmd = 'cd ' . __DIR__ . '/../../resources && python3 hon_get_tokens.py ' . escapeshellarg($email) . ' ' . escapeshellarg($password);
        
        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie tokens : ' . $output);
        
        $tokens = json_decode($output, true);
        
        if (!is_array($tokens) || !isset($tokens['id_token']) || !isset($tokens['cognito_token'])) {
            log::add('hon', 'error', 'Format de tokens invalide : ' . $output);
            return false;
        }
        
        return $tokens;
    }

    /**
     * Récupère la liste des équipements
     */
    private static function getDevices($idToken, $cognitoToken) {
        $cmd = 'cd ' . __DIR__ . '/../../resources && python3 hon_get_appliances.py ' . 
               escapeshellarg($idToken) . ' ' . escapeshellarg($cognitoToken);
        
        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie équipements : ' . $output);
        
        $devices = self::extractJsonFromOutput($output);
        
        if (!is_array($devices)) {
            log::add('hon', 'error', 'Format de données équipements invalide : ' . $output);
            return false;
        }
        
        return $devices;
    }

    /**
     * Extrait le JSON depuis la sortie des scripts Python
     */
    private static function extractJsonFromOutput($output) {
        // Essayer d'abord de décoder directement
        $result = json_decode(trim($output), true);
        if (is_array($result)) {
            return $result;
        }
        
        // Sinon chercher le JSON dans la sortie
        $lines = explode("\n", $output);
        $jsonOutput = '';
        $jsonFound = false;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (($trimmedLine == '{' || $trimmedLine == '[') || $jsonFound) {
                $jsonOutput .= $line . "\n";
                $jsonFound = true;
            }
        }
        
        $result = json_decode(trim($jsonOutput), true);
        return is_array($result) ? $result : [];
    }

    /**
     * Crée ou met à jour un équipement
     */
    private static function createOrUpdateDevice($deviceData, $tokens) {
        $macAddress = $deviceData['macAddress'] ?? '';
        
        if (empty($macAddress)) {
            log::add('hon', 'warning', 'Équipement sans adresse MAC ignoré');
            return;
        }
        
        // Rechercher un équipement existant
        $eqLogic = self::byLogicalId($macAddress, 'hon');
        
        if (!is_object($eqLogic)) {
            log::add('hon', 'info', 'Création du nouvel équipement : ' . ($deviceData['nickName'] ?? $macAddress));
            $eqLogic = new hon();
            $eqLogic->setLogicalId($macAddress);
            $eqLogic->setEqType_name('hon');
        }
        
        // Configuration de base
        $eqLogic->setName($deviceData['nickName'] ?? $deviceData['applianceTypeName'] ?? 'Équipement hOn');
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        
        // Configuration spécifique hOn
        $eqLogic->setConfiguration('macAddress', $macAddress);
        $eqLogic->setConfiguration('applianceTypeName', $deviceData['applianceTypeName'] ?? '');
        $eqLogic->setConfiguration('applianceTypeId', $deviceData['applianceTypeId'] ?? '');
        $eqLogic->setConfiguration('brand', $deviceData['brand'] ?? '');
        $eqLogic->setConfiguration('modelName', $deviceData['modelName'] ?? '');
        $eqLogic->setConfiguration('lastSync', date('Y-m-d H:i:s'));
        
        $eqLogic->save();
        
        log::add('hon', 'info', 'Équipement sauvegardé : ' . $eqLogic->getName() . ' (MAC: ' . $macAddress . ')');
    }

    /*     * *********************Méthodes d'instance************************* */

    /**
     * Fonction exécutée automatiquement avant la sauvegarde de l'équipement
     */
    public function preSave() {
        
    }

    /**
     * Fonction exécutée automatiquement après la sauvegarde de l'équipement
     */
    public function postSave() {
        // Créer les commandes seulement si elles n'existent pas déjà
        $this->createCommands();
    }

    /**
     * Crée les commandes pour l'équipement en utilisant les fichiers JSON
     */
    private function createCommands() {
        $applianceType = strtoupper($this->getConfiguration('applianceTypeName', ''));
        
        // Déterminer le fichier JSON à utiliser
        $jsonFile = '';
        if (in_array($applianceType, ['WM', 'WASHING_MACHINE'])) {
            $jsonFile = __DIR__ . '/../../data/devices/washing_machine.json';
        } elseif (in_array($applianceType, ['TD', 'TUMBLE_DRYER'])) {
            $jsonFile = __DIR__ . '/../../data/devices/td.json';
        } else {
            log::add('hon', 'warning', 'Type d\'équipement non supporté: ' . $applianceType);
            return;
        }
        
        if (!file_exists($jsonFile)) {
            log::add('hon', 'error', 'Fichier de définition non trouvé: ' . $jsonFile);
            return;
        }
        
        // Charger les définitions depuis le JSON
        $jsonContent = file_get_contents($jsonFile);
        $deviceDef = json_decode($jsonContent, true);
        
        if (!$deviceDef || !isset($deviceDef['capabilities'])) {
            log::add('hon', 'error', 'Fichier JSON invalide: ' . $jsonFile);
            return;
        }
        
        $capabilities = $deviceDef['capabilities'];
        
        // Créer les binary sensors
        if (isset($capabilities['binary_sensors'])) {
            foreach ($capabilities['binary_sensors'] as $sensor) {
                $this->createCommandFromJson($sensor, 'info', 'binary');
            }
        }
        
        // Créer les sensors numériques
        if (isset($capabilities['sensors'])) {
            foreach ($capabilities['sensors'] as $sensor) {
                $this->createCommandFromJson($sensor, 'info', 'numeric');
            }
        }
        
        // Créer les selects (info string pour Jeedom)
        if (isset($capabilities['selects'])) {
            foreach ($capabilities['selects'] as $select) {
                $this->createCommandFromJson($select, 'info', 'string');
            }
        }
        
        // Créer les texts
        if (isset($capabilities['texts'])) {
            foreach ($capabilities['texts'] as $text) {
                $this->createCommandFromJson($text, 'info', 'string');
            }
        }
        
        // Créer les datetime sensors
        if (isset($capabilities['datetime_sensors'])) {
            foreach ($capabilities['datetime_sensors'] as $datetime) {
                $this->createCommandFromJson($datetime, 'info', 'string');
            }
        }
        
        // Créer les commandes d'action
        if (isset($deviceDef['commands'])) {
            foreach ($deviceDef['commands'] as $command) {
                $this->createCommandFromJson($command, 'action', 'other');
            }
        }
        
        log::add('hon', 'info', 'Commandes créées pour ' . $this->getName() . ' depuis ' . basename($jsonFile));
    }

    /**
     * Crée une commande à partir d'une définition JSON
     */
    private function createCommandFromJson($def, $type, $subtype) {
        $logicalId = $def['id'];
        
        // Vérifier si la commande existe déjà
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            return; // Commande déjà créée
        }
        
        // Créer la nouvelle commande
        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($def['name']);
        $cmd->setType($type);
        $cmd->setSubType($subtype);
        
        // Ajouter l'unité si présente
        if (isset($def['unit_of_measurement'])) {
            $cmd->setUnite($def['unit_of_measurement']);
        }
        
        // Configuration pour les templates/icônes
        if (isset($def['icon'])) {
            $cmd->setConfiguration('icon', $def['icon']);
        }
        
        if (isset($def['device_class'])) {
            $cmd->setConfiguration('device_class', $def['device_class']);
        }
        
        // Pour les binary sensors, configurer les états
        if ($subtype == 'binary' && isset($def['state_on']) && isset($def['state_off'])) {
            $cmd->setConfiguration('state_on', $def['state_on']);
            $cmd->setConfiguration('state_off', $def['state_off']);
        }
        
        $cmd->setIsVisible(1);
        $cmd->save();
        
        log::add('hon', 'debug', 'Commande créée: ' . $logicalId . ' (' . $def['name'] . ')');
    }

    /**
     * Fonction exécutée automatiquement avant la suppression de l'équipement
     */
    public function preRemove() {
        
    }

    /**
     * Fonction exécutée automatiquement après la suppression de l'équipement
     */
    public function postRemove() {
        
    }

    /**
     * Retourne les informations à afficher sur le dashboard
     */
    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }

        $version = jeedom::versionAlias($_version);
        
        // Informations de base
        $replace['#name#'] = $this->getName();
        $replace['#id#'] = $this->getId();
        $replace['#background-color#'] = $this->getBackgroundColor($_version);
        $replace['#eqLogic_id#'] = $this->getId();
        
        // Informations hOn
        $replace['#macAddress#'] = $this->getConfiguration('macAddress', 'N/A');
        $replace['#applianceTypeName#'] = $this->getConfiguration('applianceTypeName', 'N/A');
        $replace['#brand#'] = $this->getConfiguration('brand', 'N/A');
        $replace['#modelName#'] = $this->getConfiguration('modelName', 'N/A');
        $replace['#lastSync#'] = $this->getConfiguration('lastSync', 'Jamais');
        
        // Icône selon le type
        $deviceType = strtoupper($this->getConfiguration('applianceTypeName', ''));
        
        if (in_array($deviceType, ['WASHING_MACHINE', 'WM'])) {
            $replace['#icon#'] = 'fas fa-tshirt';
            $replace['#deviceType#'] = 'Machine à laver';
        } elseif (in_array($deviceType, ['TUMBLE_DRYER', 'TD'])) {
            $replace['#icon#'] = 'fas fa-wind';
            $replace['#deviceType#'] = 'Sèche-linge';
        } else {
            $replace['#icon#'] = 'fas fa-question-circle';
            $replace['#deviceType#'] = 'Équipement hOn';
        }

        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'hon', 'hon')));
    }

    /**
     * Rafraîchit les informations de l'équipement avec limitation de fréquence
     */
    public function refreshInfo() {
        try {
            $macAddress = $this->getConfiguration('macAddress');
            if (empty($macAddress)) {
                return;
            }
            
            // Vérifier la dernière mise à jour pour éviter les appels trop fréquents
            $lastRefresh = (int)$this->getConfiguration('lastRefresh', 0);
            $currentTime = time();
            
            if (($currentTime - $lastRefresh) < 60) { // Minimum 1 minute entre les rafraîchissements
                return;
            }
            
            // Récupération des tokens
            $tokens = self::getCachedTokens();
            
            if (!$tokens) {
                return;
            }
            
            // Récupération des données avec le script d'extraction unifié
            $statusData = $this->getDeviceData($tokens['id_token'], $tokens['cognito_token'], $macAddress);
            
            if ($statusData && isset($statusData['data']) && is_array($statusData['data'])) {
                $hasUpdates = false;
                
                foreach ($statusData['data'] as $sensor => $value) {
                    $cmd = $this->getCmd(null, $sensor);
                    if (is_object($cmd)) {
                        // Convertir les valeurs booléennes
                        if (is_bool($value)) {
                            $value = $value ? 1 : 0;
                        }
                        
                        // Vérifier si la valeur a changé avant de déclencher un event
                        $currentValue = $cmd->execCmd();
                        if ($currentValue != $value) {
                            $cmd->event($value);
                            $hasUpdates = true;
                        }
                    }
                }
                
                // Mettre à jour le timestamp de dernière mise à jour seulement si des données ont été reçues
                $this->setConfiguration('lastRefresh', $currentTime);
                $this->save();
                
                // Log seulement s'il y a eu des mises à jour
                if ($hasUpdates) {
                    log::add('hon', 'info', 'Données mises à jour pour : ' . $this->getName());
                }
            }
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors du rafraîchissement de ' . $this->getName() . ' : ' . $e->getMessage());
        }
    }

    /**
     * Récupère les données d'un équipement avec le script unifié
     */
    private function getDeviceData($idToken, $cognitoToken, $macAddress) {
        try {
            $applianceType = $this->getConfiguration('applianceTypeName', 'WM');
            
            $cmd = 'cd ' . __DIR__ . '/../../resources && python3 hon_extraction.py --mac ' . 
                   escapeshellarg($macAddress) . ' --type ' . escapeshellarg($applianceType) . 
                   ' --id-token ' . escapeshellarg($idToken) . 
                   ' --cognito-token ' . escapeshellarg($cognitoToken) . 
                   ' --output-format jeedom 2>/dev/null';
            
            $output = shell_exec($cmd);
            
            if (empty($output)) {
                return false;
            }
            
            return self::extractJsonFromOutput($output);
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getDeviceData : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Méthodes utilitaires publiques
     */
    public function getHonTokens() {
        return self::getCachedTokens();
    }

    public function forceTokenRefresh() {
        return self::refreshTokens();
    }

    public function getTokenStatus() {
        $lastTokenTime = config::byKey('lastTokenTime', 'hon', 0);
        $currentTime = time();
        $timeDiff = $currentTime - $lastTokenTime;
        
        return [
            'lastUpdate' => date('Y-m-d H:i:s', $lastTokenTime),
            'age' => $timeDiff,
            'valid' => ($timeDiff < 19800),
            'expireIn' => max(0, 19800 - $timeDiff)
        ];
    }
}

class honCmd extends cmd {
    /**
     * Fonction exécutée automatiquement avant l'exécution de la commande
     */
    public function preSave() {
        
    }

    /**
     * Fonction exécutée automatiquement après la sauvegarde de la commande
     */
    public function postSave() {
        
    }

    /**
     * Fonction exécutée pour l'exécution des commandes
     */
    public function execute($_options = array()) {
        // Déclencher un rafraîchissement des données lors de l'exécution d'une commande
        $eqLogic = $this->getEqLogic();
        if (is_object($eqLogic)) {
            $eqLogic->refreshInfo();
        }
        
        log::add('hon', 'info', 'Exécution commande : ' . $this->getLogicalId());
    }
}

?>