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

require_once __DIR__  . '/../../../../core/php/core.inc.php';

class hon extends eqLogic {

    /* ========================= Méthodes statiques ========================= */

    /** Exécutée automatiquement toutes les minutes par Jeedom */
    public static function cron() {
        self::refreshAllDevices();
    }

    /** Exécutée automatiquement toutes les 15 minutes par Jeedom */
    public static function cron15() {
        self::checkAndRefreshTokensIfExpired();
    }

    /** Rafraîchit les données de tous les équipements hon */
    public static function refreshAllDevices() {
        try {
            $eqLogics = eqLogic::byType('hon', true);
            if (!is_array($eqLogics) || empty($eqLogics)) return;

            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'warning', 'Tokens non disponibles pour le rafraîchissement');
                return;
            }

            $devices = [];
            foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getIsEnable()) {
                    $lastRefresh = (int)$eqLogic->getConfiguration('lastRefresh', 0);
                    $currentTime = time();
                    if (($currentTime - $lastRefresh) >= 45) {
                        $macAddress = $eqLogic->getConfiguration('macAddress');
                        $macAddress = str_replace(':', '-', $macAddress);
                        $devices[] = [
                            'mac_address' => $macAddress,
                            'appliance_type' => self::getApplianceTypeCode($eqLogic->getConfiguration('applianceType')),
                            'equipment_id' => $eqLogic->getId()
                        ];
                    }
                }
            }
            if (empty($devices)) return;

            log::add('hon', 'debug', 'Rafraîchissement de ' . count($devices) . ' appareils');
            $result = self::executeQuickUpdate($devices, $tokens);

            if ($result && isset($result['devices'])) {
                foreach ($result['devices'] as $deviceResult) {
                    if ($deviceResult['success']) {
                        self::updateDeviceFromQuickDataWithTranslation($deviceResult);
                    } else {
                        log::add('hon', 'warning', 'Échec rafraîchissement ' . $deviceResult['mac_address'] . ': ' . ($deviceResult['error'] ?? 'Erreur inconnue'));
                    }
                }
            } else {
                log::add('hon', 'warning', 'Résultat invalide du script de mise à jour');
            }

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur dans refreshAllDevices : ' . $e->getMessage());
        }
    }

    /** Contrôle la validité des tokens et refresh automatique à 5h */
    public static function checkAndRefreshTokensIfExpired() {
        try {
            $lastTokenTime = (int)config::byKey('lastTokenTime', 'hon', 0);
            $currentTime   = time();
            $tokenAge      = $currentTime - $lastTokenTime;

            if ($lastTokenTime === 0) {
                log::add('hon', 'warning', 'Aucun token généré - une synchronisation manuelle est requise');
                return;
            }

            if ($tokenAge >= 18000) { // 5h
                log::add('hon', 'info', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - refresh automatique...');
                $refreshSuccess = self::refreshTokens();
                if ($refreshSuccess) log::add('hon', 'info', 'Refresh automatique des tokens réussi');
                else log::add('hon', 'error', 'Échec du refresh automatique des tokens');
                return;
            }

            if ($tokenAge > 18000) {
                log::add('hon', 'info', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - refresh bientôt');
            } elseif ($tokenAge > 14400) {
                log::add('hon', 'debug', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - encore valides');
            } else {
                log::add('hon', 'debug', 'Tokens valides (âge: ' . round($tokenAge/3600, 1) . 'h)');
            }

            $cachedIdToken      = config::byKey('cachedIdToken', 'hon');
            $cachedCognitoToken = config::byKey('cachedCognitoToken', 'hon');
            if (empty($cachedIdToken) || empty($cachedCognitoToken)) {
                log::add('hon', 'error', 'Tokens manquants en cache - synchronisation manuelle requise');
            }

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors du contrôle de validité des tokens : ' . $e->getMessage());
        }
    }

    /** Exécute le script Python optimisé pour la mise à jour rapide */
    private static function executeQuickUpdate($devices, $tokens) {
        try {
            $resourcesDir = __DIR__ . '/../../resources';
            $scriptPath   = $resourcesDir . '/hon_cron_updater.py';

            if (!file_exists($scriptPath)) {
                log::add('hon', 'error', 'Script Python non trouvé : ' . $scriptPath);
                return false;
            }
            if (!is_executable($scriptPath)) {
                chmod($scriptPath, 0755);
                log::add('hon', 'info', 'Permissions d\'exécution ajoutées au script Python');
            }

            if (count($devices) === 1) {
                $device = $devices[0];
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_cron_updater.py ' .
                       escapeshellarg($device['mac_address']) . ' ' .
                       escapeshellarg($device['appliance_type']) . ' ' .
                       escapeshellarg($tokens['id_token']) . ' ' .
                       escapeshellarg($tokens['cognito_token']);
            } else {
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_cron_updater.py ' .
                       escapeshellarg($devices[0]['mac_address']) . ' ' .
                       escapeshellarg($devices[0]['appliance_type']) . ' ' .
                       escapeshellarg($tokens['id_token']) . ' ' .
                       escapeshellarg($tokens['cognito_token']) . ' multi';
                foreach ($devices as $device) {
                    $cmd .= ' ' . escapeshellarg($device['mac_address']) . ' ' . escapeshellarg($device['appliance_type']);
                }
            }

            $output = shell_exec($cmd . ' 2>&1');
            if (empty($output)) {
                log::add('hon', 'warning', 'Aucune sortie du script de mise à jour');
                return false;
            }
            log::add('hon', 'debug', 'Sortie complète script : ' . $output);

            $lines    = explode("\n", trim($output));
            $jsonLine = null;
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '{') === 0) { $jsonLine = $line; break; }
            }
            if (!$jsonLine) {
                log::add('hon', 'warning', 'Aucune sortie JSON trouvée');
                return false;
            }

            $result = json_decode($jsonLine, true);
            if (!is_array($result)) {
                log::add('hon', 'warning', 'JSON invalide : ' . $jsonLine);
                return false;
            }
            return $result;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur executeQuickUpdate : ' . $e->getMessage());
            return false;
        }
    }

    /** Convertit le type d'appareil en code API */
    private static function getApplianceTypeCode($applianceType) {
        $typeMapping = [
            'Machine à laver' => 'WM',
            'WM'              => 'WM',
            'Sèche-linge'     => 'TD',
            'TD'              => 'TD',
            'WD'              => 'WD', // si tu as un lave-linge séchant
        ];
        return $typeMapping[$applianceType] ?? 'WM';
    }

    /** Vérifie l'expiration des tokens et les régénère si nécessaire */
    public static function checkTokenExpiration() {
        $lastTokenTime = config::byKey('lastTokenTime', 'hon', 0);
        $currentTime   = time();
        if (($currentTime - $lastTokenTime) > 19800) {
            log::add('hon', 'info', 'Tokens expirés (> 5h30) - refresh automatique nécessaire');
            return self::refreshTokens();
        }
        return true;
    }

    /** Force la régénération des tokens */
    public static function refreshTokens() {
        try {
            $email    = config::byKey('email', 'hon');
            $password = config::byKey('password', 'hon');
            if (empty($email) || empty($password)) {
                log::add('hon', 'error', 'Email ou mot de passe manquant pour régénérer les tokens');
                return false;
            }
            $tokens = self::getTokens($email, $password);
            if (!$tokens) {
                log::add('hon', 'error', 'Impossible de régénérer les tokens hOn');
                return false;
            }
            config::save('cachedIdToken',      $tokens['id_token'],      'hon');
            config::save('cachedCognitoToken', $tokens['cognito_token'], 'hon');
            config::save('cachedMobileId',     $tokens['mobile_id'],     'hon');
            config::save('lastTokenTime',      time(),                   'hon');
            log::add('hon', 'info', 'Tokens régénérés avec succès');
            return true;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors de la régénération des tokens : ' . $e->getMessage());
            return false;
        }
    }

    /** Synchronise les équipements depuis l'API hOn */
    public static function syncDevices() {
        log::add('hon', 'info', 'Début de la synchronisation des équipements hOn');
        try {
            $email    = config::byKey('email', 'hon');
            $password = config::byKey('password', 'hon');
            if (empty($email) || empty($password)) {
                log::add('hon', 'error', 'Email ou mot de passe manquant dans la configuration');
                return false;
            }
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'error', 'Impossible de récupérer les tokens hOn');
                return false;
            }

            $dataDir = self::generateDeviceJsons($email, $password);
            if (!$dataDir) {
                log::add('hon', 'error', 'Erreur lors de la génération des JSONs');
                return false;
            }

            $indexData = self::loadDeviceIndex($dataDir);
            if (!$indexData) {
                log::add('hon', 'error', 'Impossible de charger l\'index des appareils');
                return false;
            }

            foreach ($indexData['devices'] as $deviceInfo) {
                try {
                    log::add('hon', 'info', 'Traitement équipement: ' . $deviceInfo['device']);
                    self::createOrUpdateDeviceFromJson($deviceInfo, $dataDir);
                    log::add('hon', 'info', 'Équipement terminé: ' . $deviceInfo['device']);
                } catch (Exception $e) {
                    log::add('hon', 'error', 'Erreur équipement ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
                } catch (Error $e) {
                    log::add('hon', 'error', 'Erreur fatale équipement ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
                }
            }

            log::add('hon', 'info', 'Synchronisation terminée avec succès');
            return true;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors de la synchronisation : ' . $e->getMessage());
            return false;
        }
    }

    /** Génère les fichiers JSON des programmes et informations des appareils */
    private static function generateDeviceJsons($email, $password) {
        try {
            $resourcesDir = __DIR__ . '/../../resources';
            $dataDir      = $resourcesDir . '/hon/data';

            log::add('hon', 'info', 'Génération des JSONs des programmes...');
            $cmd1    = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_json_generator_programme.py ' .
                       escapeshellarg($email) . ' ' . escapeshellarg($password) . ' 2>&1';
            $output1 = shell_exec($cmd1);
            log::add('hon', 'debug', 'Sortie génération programmes : ' . $output1);

            log::add('hon', 'info', 'Génération des JSONs des informations des appareils...');
            $cmd2    = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_json_generator_device.py ' .
                       escapeshellarg($email) . ' ' . escapeshellarg($password) . ' 2>&1';
            $output2 = shell_exec($cmd2);
            log::add('hon', 'debug', 'Sortie génération infos : ' . $output2);

            $possiblePaths = [
                $dataDir,
                __DIR__ . '/../../data',
                __DIR__ . '/../../../data',
                $resourcesDir . '/../data',
                '/tmp/hon/data'
            ];
            $actualDataDir = null;
            foreach ($possiblePaths as $path) {
                $files = glob($path . '/*.json');
                if (!empty($files)) { $actualDataDir = $path; break; }
            }
            if (!$actualDataDir) {
                log::add('hon', 'error', 'Aucun fichier JSON généré');
                return false;
            }

            config::save('dataDir', $actualDataDir, 'hon');
            return $actualDataDir;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur génération JSONs : ' . $e->getMessage());
            return false;
        }
    }

    /** Charge l'index des appareils */
    private static function loadDeviceIndex($dataDir) {
        try {
            $indexFiles = [
                $dataDir . '/hon_devices_complete_index.json',
                $dataDir . '/hon_devices_index.json'
            ];
            $indexFile = null;
            foreach ($indexFiles as $file) { if (file_exists($file)) { $indexFile = $file; break; } }

            if (!$indexFile) return self::createManualIndex($dataDir);

            $indexContent = file_get_contents($indexFile);
            $rawIndexData = json_decode($indexContent, true);
            if (!$rawIndexData || !isset($rawIndexData['devices'])) {
                return self::createManualIndex($dataDir);
            }

            if (basename($indexFile) === 'hon_devices_complete_index.json') {
                return self::adaptCompleteIndex($rawIndexData);
            } else {
                return self::adaptStandardIndex($rawIndexData);
            }

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur chargement index : ' . $e->getMessage());
            return self::createManualIndex($dataDir);
        }
    }

    private static function adaptCompleteIndex($rawIndexData) {
        $indexData = [
            'generated_at' => $rawIndexData['extraction_info']['extracted_at'] ?? date('Y-m-d H:i:s'),
            'total_devices' => count($rawIndexData['devices']),
            'devices' => []
        ];
        foreach ($rawIndexData['devices'] as $deviceInfo) {
            $filename = $deviceInfo['filename'];
            $mac      = str_replace(['device_', '.json'], '', $filename);
            $mac      = str_replace('-', ':', $mac);
            $indexData['devices'][] = [
                'file'         => $deviceInfo['file'] ?? $filename,
                'filename'     => $filename,
                'device'       => $deviceInfo['device'],
                'mac'          => $mac,
                'mac_filename' => str_replace(':', '-', $mac),
                'type'         => $deviceInfo['type'],
                'programs'     => $deviceInfo['programs'] ?? 0,
                'settings'     => $deviceInfo['settings'] ?? 0
            ];
        }
        return $indexData;
    }

    private static function adaptStandardIndex($rawIndexData) {
        return $rawIndexData;
    }

    private static function createManualIndex($dataDir) {
        try {
            $deviceFiles = glob($dataDir . '/device_*.json');
            if (empty($deviceFiles)) {
                log::add('hon', 'error', 'Aucun fichier device_*.json trouvé pour créer l\'index manuel');
                return false;
            }

            $indexData = [
                'generated_at' => date('Y-m-d H:i:s'),
                'total_devices' => 0,
                'devices' => []
            ];

            foreach ($deviceFiles as $deviceFile) {
                $filename = basename($deviceFile);
                $mac      = str_replace(['device_', '.json'], '', $filename);
                $mac      = str_replace('-', ':', $mac);

                $deviceData = json_decode(file_get_contents($deviceFile), true);
                if (!$deviceData) continue;

                $deviceName = $deviceData['device_info']['nickname'] ??
                              $deviceData['appliance']['applianceModelName'] ??
                              $deviceData['nickname'] ?? 'Appareil hOn';

                $deviceType = $deviceData['device_info']['applianceTypeName'] ??
                              $deviceData['appliance']['applianceTypeName'] ??
                              $deviceData['applianceTypeName'] ?? 'unknown';

                $indexData['devices'][] = [
                    'file'         => $filename,
                    'filename'     => $filename,
                    'device'       => $deviceName,
                    'mac'          => $mac,
                    'mac_filename' => str_replace(':', '-', $mac),
                    'type'         => $deviceType,
                    'programs'     => 0,
                    'settings'     => 0
                ];
            }

            $indexData['total_devices'] = count($indexData['devices']);
            log::add('hon', 'info', 'Index manuel créé avec ' . count($indexData['devices']) . ' appareils');
            return $indexData;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur création index manuel : ' . $e->getMessage());
            return false;
        }
    }

    /** Récupère les tokens (cache) */
    public static function getCachedTokens() {
        try {
            $lastTokenTime = (int)config::byKey('lastTokenTime', 'hon', 0);
            $currentTime   = time();
            if (($currentTime - $lastTokenTime) < 19800) {
                $cachedIdToken      = config::byKey('cachedIdToken', 'hon');
                $cachedCognitoToken = config::byKey('cachedCognitoToken', 'hon');
                $cachedMobileId     = config::byKey('cachedMobileId', 'hon');
                if (!empty($cachedIdToken) && !empty($cachedCognitoToken)) {
                    return [
                        'id_token'       => $cachedIdToken,
                        'cognito_token'  => $cachedCognitoToken,
                        'mobile_id'      => $cachedMobileId
                    ];
                }
            }
            log::add('hon', 'info', 'Tokens expirés ou manquants');
            return false;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getCachedTokens : ' . $e->getMessage());
            return false;
        }
    }

    /** Récupère les tokens d'authentification */
    private static function getTokens($email, $password) {
        $cmd    = 'cd ' . escapeshellarg(__DIR__ . '/../../resources') . ' && python3 hon_get_tokens.py ' .
                  escapeshellarg($email) . ' ' . escapeshellarg($password);
        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie tokens : ' . $output);
        $tokens = json_decode($output, true);
        if (!is_array($tokens) || !isset($tokens['id_token']) || !isset($tokens['cognito_token'])) {
            log::add('hon', 'error', 'Format de tokens invalide : ' . $output);
            return false;
        }
        return $tokens;
    }

    /** Crée / met à jour un équipement depuis les JSONs */
    private static function createOrUpdateDeviceFromJson($deviceInfo, $dataDir) {
        try {
            $macAddress = $deviceInfo['mac'] ?? '';
            if (empty($macAddress)) {
                log::add('hon', 'warning', 'Équipement sans adresse MAC ignoré');
                return;
            }

            log::add('hon', 'info', '=== DÉBUT TRAITEMENT ÉQUIPEMENT: ' . $deviceInfo['device'] . ' ===');

            $eqLogic = self::byLogicalId($macAddress, 'hon');
            if (!is_object($eqLogic)) {
                log::add('hon', 'info', 'Création du nouvel équipement : ' . $deviceInfo['device']);
                $eqLogic = new hon();
                $eqLogic->setLogicalId($macAddress);
                $eqLogic->setEqType_name('hon');
            } else {
                log::add('hon', 'info', 'Mise à jour de l\'équipement existant : ' . $deviceInfo['device']);
            }

            $eqLogic->setName($deviceInfo['device']);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('macAddress',   $macAddress);
            $eqLogic->setConfiguration('mac_filename', $deviceInfo['mac_filename']);
            $eqLogic->setConfiguration('applianceType',$deviceInfo['type']);
            $eqLogic->setConfiguration('programsCount',$deviceInfo['programs']);
            $eqLogic->setConfiguration('settingsCount',$deviceInfo['settings']);
            $eqLogic->setConfiguration('lastSync',     date('Y-m-d H:i:s'));
            $eqLogic->save();

            try {
                log::add('hon', 'info', 'Début création commandes pour : ' . $deviceInfo['device']);
                set_time_limit(0);
                $eqLogic->createCommandsFromJson($dataDir);
                log::add('hon', 'info', 'Commandes créées avec succès pour : ' . $deviceInfo['device']);
            } catch (Exception $e) {
                log::add('hon', 'error', 'Erreur création commandes pour ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
            }

            log::add('hon', 'info', '=== FIN TRAITEMENT ÉQUIPEMENT: ' . $deviceInfo['device'] . ' ===');

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur création/MAJ équipement ' . ($deviceInfo['device'] ?? 'UNKNOWN') . ' : ' . $e->getMessage());
        }
    }

    /* ========================= Méthodes d'instance ========================= */

    public function preSave() {}
    public function postSave() {}

    /** Crée les commandes à partir des JSON */
    public function createCommandsFromJson($dataDir = null) {
        $macAddress  = $this->getConfiguration('macAddress');
        $macFilename = $this->getConfiguration('mac_filename');
        if (empty($macAddress) || empty($macFilename)) {
            log::add('hon', 'error', 'MAC address manquante pour créer les commandes de ' . $this->getName());
            return;
        }
        if ($dataDir === null) {
            $dataDir = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
        }

        log::add('hon', 'debug', 'Création commandes pour ' . $this->getName() . ' dans ' . $dataDir);

        $this->createMappedInfoCommandsWithTranslation();
        $this->createActionCommandsFromPrograms($dataDir, $macFilename);
        $this->createEssentialActionCommands();
        $this->createSelectionWorkflowCommands(); // ici, création conditionnelle de set_temp pour WM/WD

        log::add('hon', 'info', 'Commandes créées pour ' . $this->getName());
    }

    /** Commandes info mappées selon le type */
    private function createMappedInfoCommands() {
        $applianceType     = $this->getConfiguration('applianceType', '');
        $applianceTypeCode = self::getApplianceTypeCode($applianceType);

        $common = [
            'machMode'           => ['name' => 'Mode machine',        'subtype' => 'numeric'],
            'prCode'             => ['name' => 'Code programme',      'subtype' => 'string'],
            'remainingTimeMM'    => ['name' => 'Temps restant',       'subtype' => 'numeric', 'unit' => 'min'],
            'doorLockStatus'     => ['name' => 'Verrouillage porte',  'subtype' => 'binary'],
            'doorStatus'         => ['name' => 'État porte',          'subtype' => 'binary'],
            'prPhase'            => ['name' => 'Phase programme',     'subtype' => 'numeric'],
            'errors'             => ['name' => 'Erreurs',             'subtype' => 'string'],
            'remoteCtrValid'     => ['name' => 'Contrôle distant',    'subtype' => 'binary'],
            'machine_state'      => ['name' => 'État machine',        'subtype' => 'string'],
            'estimated_end_time' => ['name' => 'Heure fin estimée',   'subtype' => 'string']
        ];

        $wm = [
            'temp'                   => ['name' => 'Température',             'subtype' => 'numeric', 'unit' => '°C'],
            'totalElectricityUsed'   => ['name' => 'Électricité totale',      'subtype' => 'numeric', 'unit' => 'kWh'],
            'currentElectricityUsed' => ['name' => 'Électricité actuelle',    'subtype' => 'numeric', 'unit' => 'kWh'],
            'spinSpeed'              => ['name' => 'Vitesse essorage',        'subtype' => 'numeric', 'unit' => 'rpm'],
            'totalWashCycle'         => ['name' => 'Total cycles',            'subtype' => 'numeric'],
            'currentWashCycle'       => ['name' => 'Cycle actuel',            'subtype' => 'numeric'],
            'totalWaterUsed'         => ['name' => 'Eau totale utilisée',     'subtype' => 'numeric', 'unit' => 'L'],
            'currentWaterUsed'       => ['name' => 'Eau actuelle',            'subtype' => 'numeric', 'unit' => 'L'],
            'actualWeight'           => ['name' => 'Poids estimé',            'subtype' => 'numeric', 'unit' => 'kg'],
            'autoDetergentStatus'    => ['name' => 'Auto lessive',            'subtype' => 'binary'],
            'haier_SoftenerWeight'   => ['name' => 'Poids Adoucissant',       'subtype' => 'numeric'],
            'haier_DetergentWeight'  => ['name' => 'Poids Lessive',           'subtype' => 'numeric'],
            'remainingMainWashTime'  => ['name' => 'Temps Lavage',            'subtype' => 'numeric'],
            'autoSoftenerStatus'     => ['name' => 'Auto adoucissant',        'subtype' => 'binary']
        ];

        $td = [
            'dryLevel'             => ['name' => 'Niveau séchage', 'subtype' => 'numeric'],
            'dryTimeMM'            => ['name' => 'Temps séchage',  'subtype' => 'numeric'],
            'sterilizationStatus'  => ['name' => 'Stérilisation',  'subtype' => 'binary']
        ];

        switch ($applianceTypeCode) {
            case 'WM': $info = array_merge($common, $wm); break;
            case 'TD': $info = array_merge($common, $td); break;
            case 'WD': $info = array_merge($common, $wm, $td); break;
            default:   $info = array_merge($common, $wm, $td); log::add('hon', 'warning', 'Type d\'appareil inconnu (' . $applianceType . ')'); break;
        }

        foreach ($info as $logicalId => $cfg) {
            $this->createInfoCommand($logicalId, $cfg['name'], $cfg['subtype'], $cfg['unit'] ?? '');
        }
        log::add('hon', 'info', count($info) . ' commandes info créées pour ' . $this->getName());
    }

    /** Crée les commandes d'action depuis les programmes (sélection) */
    private function createActionCommandsFromPrograms($dataDir, $macFilename) {
        try {
            $patterns = [
                $dataDir . '/' . $macFilename . '_*.json',
                $dataDir . '/*' . str_replace('-', '_', $macFilename) . '*.json',
                $dataDir . '/*' . str_replace('-', '', $macFilename) . '*.json'
            ];
            $programFiles = [];
            foreach ($patterns as $pattern) {
                $files = glob($pattern);
                if (!empty($files)) {
                    $programFiles = array_merge($programFiles, $files);
                    log::add('hon', 'debug', 'Programmes trouvés avec pattern: ' . $pattern);
                    break;
                }
            }
            if (empty($programFiles)) {
                log::add('hon', 'warning', 'Aucun programme trouvé pour ' . $macFilename);
                $allFiles = glob($dataDir . '/*.json');
                log::add('hon', 'debug', 'Fichiers disponibles: ' . implode(', ', array_map('basename', $allFiles)));
                return;
            }

            $programsCreated = 0;
            foreach ($programFiles as $programFile) {
                $content     = file_get_contents($programFile);
                $programData = json_decode($content, true);
                if (!$programData) {
                    log::add('hon', 'warning', 'JSON invalide dans: ' . basename($programFile));
                    continue;
                }

                $programName = '';
                $displayName = '';

                if (isset($programData['program_info'])) {
                    $programInfo = $programData['program_info'];
                    $programName = $programInfo['name'] ?? '';
                    $displayName = $programInfo['display_name'] ?? $programInfo['displayName'] ?? $programName;
                } elseif (isset($programData['program'])) {
                    $programInfo = $programData['program'];
                    $programName = $programInfo['name'] ?? '';
                    $displayName = $programInfo['display_name'] ?? $programInfo['displayName'] ?? $programName;
                } elseif (isset($programData['name'])) {
                    $programName = $programData['name'];
                    $displayName = $programData['display_name'] ?? $programData['displayName'] ?? $programName;
                } else {
                    $fileName = basename($programFile, '.json');
                    $parts    = explode('_', $fileName);
                    if (count($parts) > 1) {
                        array_shift($parts);
                        $programName = implode('_', $parts);
                        $displayName = ucwords(str_replace('_', ' ', $programName));
                    } else {
                        $programName = $fileName;
                        $displayName = $programName;
                    }
                }

                if (empty($programName)) {
                    log::add('hon', 'warning', 'Nom de programme vide dans: ' . basename($programFile));
                    continue;
                }

                $cleanProgramName = preg_replace('/[^a-zA-Z0-9_]/', '_', $programName);
                $cleanProgramName = trim($cleanProgramName, '_');
                if (empty($cleanProgramName)) {
                    log::add('hon', 'warning', 'Nom de programme invalide après nettoyage: ' . $programName);
                    continue;
                }

                $logicalId   = 'select_' . $cleanProgramName;
                $existingCmd = $this->getCmd(null, $logicalId);
                if (is_object($existingCmd)) {
                    log::add('hon', 'debug', 'Commande sélection déjà existante: ' . $displayName);
                    continue;
                }

                $cmd = new honCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName('Sélectionner ' . $displayName);
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setConfiguration('program', $programName);
                $cmd->setIsVisible(1);
                $cmd->save();

                $programsCreated++;
                log::add('hon', 'info', 'Commande de sélection créée: ' . $displayName . ' (' . $programName . ')');
            }

            log::add('hon', 'info', $programsCreated . ' commandes de sélection créées pour ' . $this->getName());

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur création commandes programmes : ' . $e->getMessage());
        }
    }

    /** Crée une commande d'information */
    private function createInfoCommand($logicalId, $name, $subtype, $unit = '') {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) return;

        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('info');
        $cmd->setSubType($subtype);
        if (!empty($unit)) $cmd->setUnite($unit);
        $cmd->setIsVisible(1);
        $cmd->setIsHistorized(in_array($logicalId, ['machine_state', 'remainingTimeMM', 'temp']) ? 1 : 0);
        $cmd->save();
    }

    /** Crée une commande d'action générique */
    private function createActionCommand($logicalId, $name, $program = '') {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) return;

        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('action');
        $cmd->setSubType('other');
        if (!empty($program)) $cmd->setConfiguration('program', $program);
        $cmd->setIsVisible(1);
        $cmd->save();
    }

    /** Helper : slider d’action (range) */
    private function createSliderActionCommand($logicalId, $name, $min = 0, $max = 100, $step = 1) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) return;

        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('action');
        $cmd->setSubType('slider');
        $cmd->setIsVisible(1);
        $cmd->setConfiguration('minValue', strval($min));
        $cmd->setConfiguration('maxValue', strval($max));
        $cmd->setConfiguration('step',    strval($step));
        $cmd->save();
    }

    /** Helper : select d’action (enum) */
    private function createSelectActionCommand($logicalId, $name, $values = []) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) return;

        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('action');
        $cmd->setSubType('select');
        $cmd->setIsVisible(1);

        $pairs = array_map(function($v){
            $n = is_numeric($v) ? 0 + $v : $v;
            return $n . '|' . $n;
        }, $values);
        $cmd->setConfiguration('listValue', implode(';', $pairs));
        $cmd->save();
    }

    public function preRemove() {}
    public function postRemove() {}

    /** Dashboard */
    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) return $replace;

        $version                 = jeedom::versionAlias($_version);
        $replace['#name#']       = $this->getName();
        $replace['#id#']         = $this->getId();
        $replace['#eqLogic_id#'] = $this->getId();

        $replace['#macAddress#']  = $this->getConfiguration('macAddress', 'N/A');
        $replace['#applianceType#']= $this->getConfiguration('applianceType', 'N/A');
        $replace['#programsCount#']= $this->getConfiguration('programsCount', '0');
        $replace['#lastSync#']     = $this->getConfiguration('lastSync', 'Jamais');

        $deviceType = strtolower($this->getConfiguration('applianceType', ''));
        if (strpos($deviceType, 'wash') !== false || strpos($deviceType, 'laver') !== false) {
            $replace['#icon#'] = 'fas fa-tshirt';
            $replace['#deviceType#'] = 'Machine à laver';
        } elseif (strpos($deviceType, 'dry') !== false || strpos($deviceType, 'séch') !== false) {
            $replace['#icon#'] = 'fas fa-wind';
            $replace['#deviceType#'] = 'Sèche-linge';
        } elseif (strpos($deviceType, 'dishwash') !== false || strpos($deviceType, 'vaisselle') !== false) {
            $replace['#icon#'] = 'fas fa-utensils';
            $replace['#deviceType#'] = 'Lave-vaisselle';
        } elseif (strpos($deviceType, 'oven') !== false || strpos($deviceType, 'four') !== false) {
            $replace['#icon#'] = 'fas fa-fire';
            $replace['#deviceType#'] = 'Four';
        } elseif (strpos($deviceType, 'fridge') !== false || strpos($deviceType, 'réfrigérateur') !== false) {
            $replace['#icon#'] = 'fas fa-snowflake';
            $replace['#deviceType#'] = 'Réfrigérateur';
        } elseif (strpos($deviceType, 'ac') !== false || strpos($deviceType, 'climatisation') !== false) {
            $replace['#icon#'] = 'fas fa-thermometer-half';
            $replace['#deviceType#'] = 'Climatisation';
        } else {
            $replace['#icon#'] = 'fas fa-home';
            $replace['#deviceType#'] = 'Équipement hOn';
        }

        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'hon', 'hon')));
    }

    /** Rafraîchit les informations de l'équipement */
    public function refreshInfo() {
        try {
            $macAddress = $this->getConfiguration('macAddress');
            if (empty($macAddress)) return;

            $lastRefresh = (int)$this->getConfiguration('lastRefresh', 0);
            $currentTime = time();
            if (($currentTime - $lastRefresh) < 55) return;

            $statusData = $this->getDeviceStatus($macAddress);
            if ($statusData && is_array($statusData)) {
                $hasUpdates = false;
                foreach ($statusData as $key => $value) {
                    $cmd = $this->getCmd(null, $key);
                    if (is_object($cmd)) {
                        if (is_bool($value)) $value = $value ? 1 : 0;
                        $currentValue = $cmd->execCmd();
                        if ($currentValue != $value) {
                            $cmd->event($value);
                            $hasUpdates = true;
                        }
                    }
                }
                $this->setConfiguration('lastRefresh', $currentTime);
                $this->save();
                if ($hasUpdates) log::add('hon', 'info', 'Données mises à jour pour : ' . $this->getName());
            }

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors du rafraîchissement de ' . $this->getName() . ' : ' . $e->getMessage());
        }
    }

    /** Récupère le statut d'un appareil (fichiers ou API) */
    private function getDeviceStatus($macAddress) {
        try {
            $dataDir     = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
            $macFilename = str_replace(':', '-', $macAddress);
            $deviceFile  = $dataDir . '/device_' . $macFilename . '.json';

            if (file_exists($deviceFile)) {
                $content   = file_get_contents($deviceFile);
                $deviceData= json_decode($content, true);
                if ($deviceData) {
                    $statusData = [];
                    if (isset($deviceData['current_state']['context']['shadow']['parameters'])) {
                        $parameters = $deviceData['current_state']['context']['shadow']['parameters'];
                        foreach ($parameters as $key => $paramData) {
                            if (is_array($paramData) && isset($paramData['parNewVal'])) {
                                $value = $paramData['parNewVal'];
                                if (is_numeric($value)) $value = is_float($value + 0) ? (float)$value : (int)$value;
                                $statusData[$key] = $value;
                            }
                        }
                    } elseif (isset($deviceData['current_state'])) {
                        $statusData = $deviceData['current_state'];
                    }

                    if (isset($deviceData['device_identification']['extracted_info'])) {
                        $deviceInfo = $deviceData['device_identification']['extracted_info'];
                        foreach ($deviceInfo as $key => $value) {
                            if (is_scalar($value)) $statusData['device_' . $key] = $value;
                        }
                    }
                    if (isset($deviceData['connectivity'])) {
                        $connectivity = $deviceData['connectivity'];
                        foreach ($connectivity as $key => $value) {
                            if (is_scalar($value)) $statusData['conn_' . $key] = $value;
                        }
                    }
                    if (isset($deviceData['current_state']['statistics'])) {
                        $statistics = $deviceData['current_state']['statistics'];
                        if (isset($statistics['programsCounter'])) $statusData['programs_counter'] = $statistics['programsCounter'];
                        if (isset($statistics['temperatureUsage'])) {
                            foreach ($statistics['temperatureUsage'] as $tempKey => $tempValue) {
                                $statusData['stat_' . $tempKey] = $tempValue;
                            }
                        }
                    }
                    return $statusData;
                }
            }
            return $this->getDeviceStatusFromAPI($macAddress);

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getDeviceStatus : ' . $e->getMessage());
            return false;
        }
    }

    /** Récupère le statut depuis l'API en temps réel */
    private function getDeviceStatusFromAPI($macAddress) {
        try {
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'info', 'Tokens expirés - tentative de refresh automatique');
                $refreshSuccess = self::refreshTokens();
                if ($refreshSuccess) $tokens = self::getCachedTokens();
                else return false;
            }

            $resourcesDir = __DIR__ . '/../../resources';
            $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_get_status.py ' .
                   escapeshellarg(config::byKey('email', 'hon')) . ' ' .
                   escapeshellarg(config::byKey('password', 'hon')) . ' ' .
                   escapeshellarg($macAddress);

            $output = shell_exec($cmd . ' 2>&1');
            if ($output) {
                $statusData = json_decode($output, true);
                if (is_array($statusData)) return $statusData;
            }
            return false;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getDeviceStatusFromAPI : ' . $e->getMessage());
            return false;
        }
    }

    /** Lance un programme sur l'équipement */
    public function launchProgram($programName, $parameters = []) {
        try {
            $macAddress = $this->getConfiguration('macAddress');
            if (empty($macAddress)) {
                log::add('hon', 'error', 'MAC address manquante pour lancer le programme');
                return false;
            }
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'error', 'Tokens non disponibles pour lancer le programme');
                return false;
            }

            $resourcesDir = __DIR__ . '/../../resources';
            $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_launcher.py ' .
                   escapeshellarg(config::byKey('email', 'hon')) . ' ' .
                   escapeshellarg(config::byKey('password', 'hon')) . ' ' .
                   escapeshellarg($programName) . ' ' .
                   escapeshellarg($macAddress);

            if (!empty($parameters)) {
                $paramString = '';
                foreach ($parameters as $key => $value) {
                    if (!empty($paramString)) $paramString .= ',';
                    $paramString .= $key . '=' . $value;
                }
                $cmd .= ' ' . escapeshellarg($paramString);
            }

            $output = shell_exec($cmd . ' 2>&1');
            log::add('hon', 'info', 'Lancement programme ' . $programName . ' sur ' . $this->getName() . ' : ' . $output);

            $success = (strpos($output, 'SUCCÈS') !== false ||
                        strpos($output, 'SUCCESS') !== false ||
                        strpos($output, 'Programme lancé') !== false ||
                        strpos($output, 'PROGRAMME MIS EN PAUSE') !== false ||
                        strpos($output, 'Commande envoyée avec succès') !== false);

            if ($success) $this->scheduleRefresh(5);
            return $success;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lancement programme : ' . $e->getMessage());
            return false;
        }
    }

    /** Programme un rafraîchissement différé */
    public function scheduleRefresh($delaySeconds = 5) {
        $cmd = "sleep $delaySeconds && php " . __DIR__ . "/../../../../core/php/jeedom.php " .
               "eqLogic::byId(" . $this->getId() . ")->refreshInfo()";
        exec($cmd . " > /dev/null 2 &>/dev/null &");
    }

    public function getHonTokens()        { return self::getCachedTokens(); }
    public function forceTokenRefresh()   { return self::refreshTokens(); }

    public function getTokenStatus() {
        $lastTokenTime = config::byKey('lastTokenTime', 'hon', 0);
        $currentTime   = time();
        $timeDiff      = $currentTime - $lastTokenTime;
        return [
            'lastUpdate' => date('Y-m-d H:i:s', $lastTokenTime),
            'age'        => $timeDiff,
            'valid'      => ($timeDiff < 19800),
            'expireIn'   => max(0, 19800 - $timeDiff)
        ];
    }

    public static function forceSyncDevices() {
        log::add('hon', 'info', 'Synchronisation manuelle déclenchée');
        return self::syncDevices();
    }

    public static function getPluginStats() {
        $equipments       = eqLogic::byType('hon');
        $totalCommands    = 0;
        $enabledEquipments= 0;
        foreach ($equipments as $equipment) {
            if ($equipment->getIsEnable()) $enabledEquipments++;
            $totalCommands += count($equipment->getCmd());
        }
        return [
            'total_equipments'  => count($equipments),
            'enabled_equipments'=> $enabledEquipments,
            'total_commands'    => $totalCommands,
            'data_dir'          => config::byKey('dataDir', 'hon', 'Non configuré'),
            'last_sync'         => config::byKey('lastSyncTime', 'hon', 'Jamais')
        ];
    }

    /* ========================= Traductions ========================= */

    private static $translationCache = [];

    private static function loadTranslations($applianceType) {
        if (isset(self::$translationCache[$applianceType])) {
            return self::$translationCache[$applianceType];
        }
        $resourcesDir   = __DIR__ . '/../../resources';
        $translationFile= $resourcesDir . '/' . $applianceType . '_programme.json';
        if (!file_exists($translationFile)) {
            log::add('hon', 'warning', 'Fichier de traduction non trouvé : ' . $translationFile);
            return null;
        }
        $content      = file_get_contents($translationFile);
        $translations = json_decode($content, true);
        if (!$translations) {
            log::add('hon', 'error', 'JSON invalide dans le fichier de traduction : ' . $translationFile);
            return null;
        }
        self::$translationCache[$applianceType] = $translations;
        return $translations;
    }

    public static function translateProgramCode($programCode, $applianceType = 'WM') {
        $translations = self::loadTranslations($applianceType);
        if (!$translations || !isset($translations['programs'])) return "";
        foreach ($translations['programs'] as $program) {
            if ((isset($program['code']) && $program['code'] == $programCode) ||
                (isset($program['prCode']) && $program['prCode'] == $programCode)) {
                return $program['display_name'] ?? $program['name'] ?? "";
            }
        }
        return "";
    }

    public static function translateDryLevel($dryLevel, $applianceType = 'TD') {
        $translations = self::loadTranslations($applianceType);
        if (!$translations || !isset($translations['dry_levels'])) return "Niveau " . $dryLevel;
        foreach ($translations['dry_levels'] as $level) {
            if ((isset($level['code']) && $level['code'] == $dryLevel) ||
                (isset($level['value']) && $level['value'] == $dryLevel)) {
                return $level['display_name'] ?? $level['name'] ?? "Niveau " . $dryLevel;
            }
        }
        return "Niveau " . $dryLevel;
    }

    /** Mise à jour depuis quick data + traductions + reset "Prêt" */
    private static function updateDeviceFromQuickDataWithTranslation($deviceResult) {
        try {
            $macAddress = $deviceResult['mac_address'];
            $eqLogic = self::byLogicalId($macAddress, 'hon');
            if (!is_object($eqLogic)) {
                $macWithColons = str_replace('-', ':', $macAddress);
                $eqLogic = self::byLogicalId($macWithColons, 'hon');
            }
            if (!is_object($eqLogic)) {
                log::add('hon', 'warning', 'Équipement non trouvé pour MAC : ' . $macAddress);
                return;
            }
            $data        = $deviceResult['data'] ?? [];
            $hasUpdates  = false;
            $applianceType     = $eqLogic->getConfiguration('applianceType', 'WM');
            $applianceTypeCode = self::getApplianceTypeCode($applianceType);

            $keyMapping = [
                'machine_mode'             => 'machMode',
                'program_code'             => 'prCode',
                'remaining_time'           => 'remainingTimeMM',
                'door_lock'                => 'doorLockStatus',
                'door_status'              => 'doorStatus',
                'errors'                   => 'errors',
                'temperature'              => 'temp',
                'spin_speed'               => 'spinSpeed',
                'total_cycles'             => 'totalWashCycle',
                'current_cycle'            => 'currentWashCycle',
                'remote_control'           => 'remoteCtrValid',
                'total_water_used'         => 'totalWaterUsed',
                'current_water_used'       => 'currentWaterUsed',
                'total_electricity_used'   => 'totalElectricityUsed',
                'current_electricity_used' => 'currentElectricityUsed',
                'estimated_weight'         => 'actualWeight',
                'auto_detergent'           => 'autoDetergentStatus',
                'auto_softener'            => 'autoSoftenerStatus',
                'dry_level'                => 'dryLevel',
                'sterilization_status'     => 'sterilizationStatus',
                'status'                   => 'machine_state',
                'program_phase'            => 'prPhase',
                'haier_Softener_Weight'    => 'haier_SoftenerWeight',
                'haier_Detergent_Weight'   => 'haier_DetergentWeight',
                'remaining_Main_Wash_Time' => 'remainingMainWashTime',
                'dry_Time_MM'              => 'dryTimeMM',
                'estimated_end_time'       => 'estimated_end_time'
            ];

            foreach ($data as $pythonKey => $valueData) {
                if (!isset($keyMapping[$pythonKey])) continue;
                $cmdLogicalId = $keyMapping[$pythonKey];
                $cmd          = $eqLogic->getCmd(null, $cmdLogicalId);
                if (!is_object($cmd)) continue;

                $newValue = $valueData['value'];

                if ($pythonKey === 'program_code') {
                    $translatedValue = self::translateProgramCode($newValue, $applianceTypeCode);
                    $translatedCmd   = $eqLogic->getCmd(null, 'prCodeTranslated');
                    if (!is_object($translatedCmd)) {
                        $translatedCmd = new honCmd();
                        $translatedCmd->setLogicalId('prCodeTranslated');
                        $translatedCmd->setEqLogic_id($eqLogic->getId());
                        $translatedCmd->setName('Nom du programme');
                        $translatedCmd->setType('info');
                        $translatedCmd->setSubType('string');
                        $translatedCmd->setIsVisible(1);
                        $translatedCmd->save();
                    }
                    $currentTranslatedValue = $translatedCmd->execCmd();
                    if ($currentTranslatedValue != $translatedValue) {
                        $translatedCmd->event($translatedValue);
                        $hasUpdates = true;
                    }
                } elseif ($pythonKey === 'dry_level') {
                    $translatedValue = self::translateDryLevel($newValue, $applianceTypeCode);
                    $translatedCmd   = $eqLogic->getCmd(null, 'dryLevelTranslated');
                    if (!is_object($translatedCmd)) {
                        $translatedCmd = new honCmd();
                        $translatedCmd->setLogicalId('dryLevelTranslated');
                        $translatedCmd->setEqLogic_id($eqLogic->getId());
                        $translatedCmd->setName('Niveau de séchage');
                        $translatedCmd->setType('info');
                        $translatedCmd->setSubType('string');
                        $translatedCmd->setIsVisible(1);
                        $translatedCmd->save();
                    }
                    $currentTranslatedValue = $translatedCmd->execCmd();
                    if ($currentTranslatedValue != $translatedValue) {
                        $translatedCmd->event($translatedValue);
                        $hasUpdates = true;
                    }
                }

                $currentValue = $cmd->execCmd();
                if (is_bool($newValue)) $newValue = $newValue ? 1 : 0;
                if ($currentValue != $newValue) {
                    $cmd->event($newValue);
                    $hasUpdates = true;
                }
            }

            // Reset quand machine "Prêt"
            if (isset($data['status']) && $data['status']['value'] === 'Prêt') {
                $applianceType     = $eqLogic->getConfiguration('applianceType', 'WM');
                $applianceTypeCode = self::getApplianceTypeCode($applianceType);

                $commonToReset = [
                    'remainingTimeMM'  => 0,
                    'prCode'           => '0',
                    'prCodeTranslated' => ''
                ];
                $wmToReset = [
                    'temp'                   => 0,
                    'spinSpeed'              => 0,
                    'currentWaterUsed'       => 0.0,
                    'currentElectricityUsed' => 0.0,
                    'actualWeight'           => 0
                ];
                $tdToReset = [
                    'dryLevel'             => 0,
                    'dryLevelTranslated'   => ''
                ];

                $toReset = $commonToReset;
                switch ($applianceTypeCode) {
                    case 'WM': $toReset = array_merge($toReset, $wmToReset); break;
                    case 'TD': $toReset = array_merge($toReset, $tdToReset); break;
                    case 'WD': $toReset = array_merge($toReset, $wmToReset, $tdToReset); break;
                }

                foreach ($toReset as $cmdLogicalId => $resetValue) {
                    $cmd = $eqLogic->getCmd(null, $cmdLogicalId);
                    if (is_object($cmd)) {
                        $currentValue = $cmd->execCmd();
                        if ($currentValue != $resetValue) {
                            $cmd->event($resetValue);
                            $hasUpdates = true;
                        }
                    }
                }
            }

            $eqLogic->setConfiguration('lastRefresh', time());
            $eqLogic->save();
            if ($hasUpdates) log::add('hon', 'debug', 'Données mises à jour pour : ' . $eqLogic->getName());
            else             log::add('hon', 'debug', 'Aucune mise à jour pour : ' . $eqLogic->getName());

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur updateDeviceFromQuickDataWithTranslation : ' . $e->getMessage());
        }
    }

    /* ========================= JSON programme & température ========================= */

    private function getProgramJsonPath($programName) {
        $dataDir     = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
        $macFilename = $this->getConfiguration('mac_filename', '');
        if ($macFilename === '') {
            $mac         = $this->getConfiguration('macAddress', '');
            $macFilename = str_replace(':', '-', $mac);
        }
        $direct = $dataDir . '/' . $macFilename . '_' . $programName . '.json';
        if (file_exists($direct)) return $direct;
        $candidates = glob($dataDir . '/*' . $programName . '*.json');
        if (!empty($candidates)) return $candidates[0];
        return null;
    }

    private function readProgramJson($programName) {
        $path = $this->getProgramJsonPath($programName);
        if (!$path || !file_exists($path)) {
            log::add('hon', 'debug', 'JSON programme introuvable pour ' . $programName);
            return null;
        }
        $content = @file_get_contents($path);
        $json    = @json_decode($content, true);
        if (!is_array($json)) {
            log::add('hon', 'warning', 'JSON invalide: ' . basename($path));
            return null;
        }
        return $json;
    }

    public function getTempSpecForProgram($programName) {
        $json = $this->readProgramJson($programName);
        if (!$json) return null;

        $root   = $json['program_info'] ?? $json['program'] ?? $json;
        $params = $root['parameters']   ?? $root['params']  ?? $root['options'] ?? null;
        if (!is_array($params)) return null;

        $temp = $params['temp'] ?? ($params['temperature'] ?? null);
        if (!is_array($temp)) return null;

        if (isset($temp['type']) && strtolower($temp['type']) === 'range') {
            $min  = isset($temp['min'])  ? floatval($temp['min'])  : 0;
            $max  = isset($temp['max'])  ? floatval($temp['max'])  : 100;
            $step = isset($temp['step']) ? floatval($temp['step']) : 1;
            return ['kind'=>'range','min'=>$min,'max'=>$max,'step'=>$step];
        }

        if (isset($temp['type']) && strtolower($temp['type']) === 'enum' && isset($temp['values']) && is_array($temp['values'])) {
            $values = array_map(function($v){ return is_numeric($v) ? 0 + $v : $v; }, $temp['values']);
            $values = array_values(array_unique($values));
            if (!empty($values)) return ['kind'=>'enum','values'=>$values];
        }

        if (isset($temp['min']) || isset($temp['max'])) {
            $min  = isset($temp['min'])  ? floatval($temp['min'])  : 0;
            $max  = isset($temp['max'])  ? floatval($temp['max'])  : 100;
            $step = isset($temp['step']) ? floatval($temp['step']) : 1;
            return ['kind'=>'range','min'=>$min,'max'=>$max,'step'=>$step];
        }

        if (isset($temp['values']) && is_array($temp['values'])) {
            $values = array_map(function($v){ return is_numeric($v) ? 0 + $v : $v; }, $temp['values']);
            $values = array_values(array_unique($values));
            if (!empty($values)) return ['kind'=>'enum','values'=>$values];
        }

        return null;
    }

    public function normalizeTempToSpec($value, $spec) {
        if (!is_array($spec)) return $value;

        if ($spec['kind'] === 'range') {
            $v    = max($spec['min'], min($spec['max'], $value));
            $step = max(1, floatval($spec['step']));
            $v    = round(($v - $spec['min']) / $step) * $step + $spec['min'];
            return is_numeric($v) ? 0 + $v : $v;
        }

        $best = null; $bestd = PHP_FLOAT_MAX;
        foreach ($spec['values'] as $cand) {
            if (!is_numeric($cand) || !is_numeric($value)) continue;
            $d = abs($value - $cand);
            if ($d < $bestd) { $bestd = $d; $best = 0 + $cand; }
        }
        return ($best !== null) ? $best : $value;
    }

    /**
     * Applique la spec au widget set_temp
     * - uniquement pour WM/WD
     * - pas de programme -> liste vide (aucun)
     * - enum -> select sans valeur par défaut
     * - range -> slider, desired_temp laissé vide tant que l’utilisateur n’a pas choisi
     */
    public function applyTempSpecForProgram($programName) {
        // 🚫 ne gérer la température que pour WM/WD
        $applianceType = $this->getConfiguration('applianceType', '');
        $applianceCode = self::getApplianceTypeCode($applianceType);
        if (!in_array($applianceCode, ['WM', 'WD'])) {
            return;
        }

        $spec = $this->getTempSpecForProgram($programName);

        // S’assurer que la commande existe (par défaut select vide)
        $cmd = $this->getCmd(null, 'set_temp');
        if (!is_object($cmd)) {
            $this->createSelectActionCommand('set_temp', 'Régler la température', []);
            $cmd = $this->getCmd(null, 'set_temp');
        }

        // Aucun param température -> select vide (aucune option)
        if (!$spec) {
            $cmd->setIsVisible(1);
            $cmd->setSubType('select');
            $cmd->setConfiguration('listValue', '');
            $cmd->setConfiguration('minValue', null);
            $cmd->setConfiguration('maxValue', null);
            $cmd->setConfiguration('step', null);
            $cmd->save();

            $info = $this->getCmd(null, 'desired_temp');
            if (is_object($info)) $info->event('');
            return;
        }

        if ($spec['kind'] === 'range') {
            $cmd->setIsVisible(1);
            $cmd->setSubType('slider');
            $cmd->setConfiguration('minValue', strval($spec['min']));
            $cmd->setConfiguration('maxValue', strval($spec['max']));
            $cmd->setConfiguration('step',     strval(max(1, $spec['step'])));
            $cmd->setConfiguration('listValue', null);
            $cmd->save();
            return;
        }

        // enum
        $vals = array_map(function($v){ $n=is_numeric($v)?0+$v:$v; return $n.'|'.$n; }, $spec['values']);
        $cmd->setIsVisible(1);
        $cmd->setSubType('select');
        $cmd->setConfiguration('listValue', implode(';', $vals));
        $cmd->setConfiguration('minValue', null);
        $cmd->setConfiguration('maxValue', null);
        $cmd->setConfiguration('step', null);
        $cmd->save();
      $this->refreshWidget();
    }

    /**
     * Workflow sélection → démarrage
     * - set_temp créé UNIQUEMENT pour WM/WD, et en SELECT VIDE par défaut
     */
private function createSelectionWorkflowCommands() {
    // Infos
    $this->createInfoCommand('selectedProgram',     'Programme sélectionné', 'string');
    $this->createInfoCommand('selectedProgramInfo', 'Infos du programme',    'string');

    // Boutons
    $this->createActionCommand('start_selected',  'Démarrer le programme sélectionné');
    $this->createActionCommand('clear_selection', 'Effacer la sélection');

    // Type d’appareil
    $applianceType = $this->getConfiguration('applianceType', '');
    $applianceCode = self::getApplianceTypeCode($applianceType);

// Température uniquement pour WM/WD
if (in_array($applianceCode, ['WM', 'WD'])) {

    $this->createInfoCommand('desired_temp', 'Température choisie', 'numeric', '°C');

    $values = [0, 20, 30, 40, 60, 90];
    $pairs  = array_map(function($v){ return $v . '|' . $v; }, $values);
    $listValue = implode(';', $pairs);

    $setTemp = $this->getCmd(null, 'set_temp');
    if (!is_object($setTemp)) {
        $this->createSelectActionCommand('set_temp', 'Régler la température', $values);
        $setTemp = $this->getCmd(null, 'set_temp'); // <-- important: recharger l'objet
    } else {
        $setTemp->setType('action');
        $setTemp->setSubType('select');
        $setTemp->setIsVisible(1);
        $setTemp->setName('Régler la température');
        $setTemp->setConfiguration('listValue', $listValue);
        $setTemp->save();
    }

    // ✅ Lier la valeur affichée du widget set_temp à desired_temp
    $desired = $this->getCmd(null, 'desired_temp');
    if (is_object($desired) && is_object($setTemp)) {
        $setTemp->setValue($desired->getId());
        $setTemp->save();
    }
}
}




    private function createMappedInfoCommandsWithTranslation() {
        $applianceType     = $this->getConfiguration('applianceType', '');
        $applianceTypeCode = self::getApplianceTypeCode($applianceType);
        $this->createMappedInfoCommands();
        $this->createInfoCommand('prCodeTranslated', 'Nom du programme', 'string');
        if (in_array($applianceTypeCode, ['TD', 'WD'])) {
            $this->createInfoCommand('dryLevelTranslated', 'Niveau de séchage', 'string');
        }
    }

    private function createEssentialActionCommands() {
        $this->createActionCommand('refresh', 'Rafraîchir');
        $this->createActionCommand('stop',    'Arrêter');
        $this->createActionCommand('pause',   'Pause');
        $this->createActionCommand('resume',  'Reprendre');
    }
}

/* ========================= Commandes ========================= */

class honCmd extends cmd {

    public function preSave() {}
    public function postSave() {}

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) throw new Exception('Équipement non trouvé');

        $logicalId = $this->getLogicalId();
        log::add('hon', 'debug', 'Exécution commande ' . $logicalId . ' sur ' . $eqLogic->getName());

        if ($logicalId == 'refresh') {
            $eqLogic->refreshInfo();
            log::add('hon', 'info', 'Rafraîchissement manuel de ' . $eqLogic->getName());
            return;

        } elseif ($logicalId == 'stop') {
            $result = $eqLogic->launchProgram('stop');
            log::add('hon', 'info', 'Arrêt de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));

        } elseif ($logicalId == 'pause') {
            $result = $eqLogic->launchProgram('pause');
            log::add('hon', 'info', 'Pause de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));

        } elseif ($logicalId == 'resume') {
            $result = $eqLogic->launchProgram('resume');
            log::add('hon', 'info', 'Reprise de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));

        } elseif ($logicalId == 'clear_selection') {
            // 1) Vide selectedProgram
            $infoSel = $eqLogic->getCmd(null, 'selectedProgram');
            if (is_object($infoSel)) { $infoSel->event(''); }

            // 2) Vide selectedProgramInfo
            $infoSum = $eqLogic->getCmd(null, 'selectedProgramInfo');
            if (!is_object($infoSum) && method_exists($eqLogic, 'createInfoCommand')) {
                $eqLogic->createInfoCommand('selectedProgramInfo', 'Infos du programme', 'string');
                $infoSum = $eqLogic->getCmd(null, 'selectedProgramInfo');
            }
            if (is_object($infoSum)) { $infoSum->event(''); }

            // 3) Vide la température mémorisée → "aucun"
            $tCmd = $eqLogic->getCmd(null, 'desired_temp');
            if (is_object($tCmd)) { $tCmd->event(''); }

            // 4) Si set_temp existe (WM/WD), on revient à LISTE VIDE
            $setTemp = $eqLogic->getCmd(null, 'set_temp');
            if (is_object($setTemp)) {
                $setTemp->setSubType('select');
                $setTemp->setConfiguration('listValue', ''); // aucune option
                $setTemp->setConfiguration('minValue', null);
                $setTemp->setConfiguration('maxValue', null);
                $setTemp->setConfiguration('step', null);
                $setTemp->setName('Régler la température');
                $setTemp->save();
            }

            log::add('hon', 'info', 'Sélection effacée sur ' . $eqLogic->getName());
            return;

        } elseif ($logicalId == 'set_temp') {
            // Valeur reçue (peut être vide ou numérique)
            $val = null;
            if (isset($_options['select']))  $val = $_options['select'];
            elseif (isset($_options['slider']))  $val = $_options['slider'];
            elseif (isset($_options['message'])) $val = $_options['message'];

            // Aucune valeur → on garde "aucun"
            if ($val === null || $val === '') {
                $val = '';
                log::add('hon', 'info', 'Température effacée → programme utilisera la température standard');
            }

            // Mémorisation
            $desired = $eqLogic->getCmd(null, 'desired_temp');
            if (!is_object($desired) && method_exists($eqLogic, 'createInfoCommand')) {
                $eqLogic->createInfoCommand('desired_temp', 'Température choisie', 'numeric', '°C');
                $desired = $eqLogic->getCmd(null, 'desired_temp');
            }
            if (is_object($desired)) $desired->event($val);

            log::add('hon', 'debug', "Température mémorisée (desired_temp) = '" . $val . "'");
            return;

        } elseif ($logicalId == 'start_selected') {
            // Démarrer programme sélectionné
            $info     = $eqLogic->getCmd(null, 'selectedProgram');
            $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
            if ($selected === '') throw new Exception('Aucun programme sélectionné');

            $parameters = [];
            if (isset($_options['parameters']) && is_array($_options['parameters'])) {
                $parameters = $_options['parameters'];
            }

            // desired_temp : si vide -> standard, sinon normaliser et transmettre
            $desiredInfo = $eqLogic->getCmd(null, 'desired_temp');
            if (is_object($desiredInfo)) {
                $raw = trim((string)$desiredInfo->execCmd());
                if ($raw === '') {
                    log::add('hon', 'info', "Température non choisie → lancement standard (valeur par défaut JSON)");
                } else {
                    if (is_numeric($raw)) {
                        $ival = 0 + $raw;
                        if (method_exists($eqLogic, 'getTempSpecForProgram') && method_exists($eqLogic, 'normalizeTempToSpec')) {
                            $spec = $eqLogic->getTempSpecForProgram($selected);
                            if ($spec) $ival = $eqLogic->normalizeTempToSpec($ival, $spec);
                        }
                        $parameters['temp'] = $ival;
                        log::add('hon', 'info', "Température choisie prise en compte → temp={$ival}");
                    } else {
                        log::add('hon', 'warning', "Température non numérique ('{$raw}') → ignorée");
                    }
                }
            }

            $result = $eqLogic->launchProgram($selected, $parameters);
            log::add('hon', 'info', 'Démarrage du programme sélectionné (' . $selected . ') sur ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
            if (!$result) throw new Exception('Échec du lancement du programme ' . $selected);

            // 🔁 rafraîchir immédiatement toutes les devices (équivalent cron)
            try {
                hon::refreshAllDevices();
            } catch (Exception $e) {
                log::add('hon', 'warning', 'RefreshAllDevices post-lancement a échoué : ' . $e->getMessage());
            }

            // Et garder un petit refresh différé
            $eqLogic->scheduleRefresh(3);
            return;

        } elseif (strpos($logicalId, 'select_') === 0) {
            // Sélection d'un programme
            $programName = $this->getConfiguration('program', '');
            if ($programName === '' || strtolower($programName) === 'none' || strtolower($programName) === 'effacer') {
                // Effacement rapide
                $infoSel = $eqLogic->getCmd(null, 'selectedProgram');
                if (is_object($infoSel)) $infoSel->event('');

                $infoSummary = $eqLogic->getCmd(null, 'selectedProgramInfo');
                if (is_object($infoSummary)) $infoSummary->event('');

                $infoTemp = $eqLogic->getCmd(null, 'desired_temp');
                if (is_object($infoTemp)) $infoTemp->event('');

                $setTemp = $eqLogic->getCmd(null, 'set_temp');
                if (is_object($setTemp)) {
                    $setTemp->setSubType('select');
                    $setTemp->setConfiguration('listValue', ''); // liste vide
                    $setTemp->setConfiguration('minValue', null);
                    $setTemp->setConfiguration('maxValue', null);
                    $setTemp->setConfiguration('step', null);
                    $setTemp->setName('Régler la température');
                    $setTemp->save();
                }

                log::add('hon', 'info', 'Effacement du programme sélectionné pour ' . $eqLogic->getName());
                return;
            }

            log::add('hon', 'info', '=== Sélection du programme : ' . $programName . ' sur ' . $eqLogic->getName() . ' ===');

            // 1) Mémoriser la sélection
            $infoSel = $eqLogic->getCmd(null, 'selectedProgram');
            if (!is_object($infoSel) && method_exists($eqLogic, 'createInfoCommand')) {
                $eqLogic->createInfoCommand('selectedProgram', 'Programme sélectionné', 'string');
                $infoSel = $eqLogic->getCmd(null, 'selectedProgram');
            }
            if (is_object($infoSel)) $infoSel->event($programName);

            // 2) Résumé
            $infoSummary = $eqLogic->getCmd(null, 'selectedProgramInfo');
            if (!is_object($infoSummary) && method_exists($eqLogic, 'createInfoCommand')) {
                $eqLogic->createInfoCommand('selectedProgramInfo', 'Infos du programme', 'string');
                $infoSummary = $eqLogic->getCmd(null, 'selectedProgramInfo');
            }

            $lines = [];
            try {
                $dataDir     = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
                $macFilename = $eqLogic->getConfiguration('mac_filename', '');
                if ($macFilename === '') {
                    $mac         = $eqLogic->getConfiguration('macAddress', '');
                    $macFilename = str_replace(':', '-', $mac);
                }
                $directFile = $dataDir . '/' . $macFilename . '_' . $programName . '.json';

                $foundData = null;
                if (file_exists($directFile)) {
                    $content = @file_get_contents($directFile);
                    $json    = @json_decode($content, true);
                    if (is_array($json)) $foundData = $json;
                }

                if ($foundData) {
                    $root   = $foundData['program_info'] ?? $foundData['program'] ?? $foundData;
                    $params = $root['parameters'] ?? $root['params'] ?? $root['options'] ?? $root;

                    $readParam = function($source, $keys) {
                        foreach ((array)$keys as $k) {
                            if (!isset($source[$k])) continue;
                            $v = $source[$k];
                            if (is_array($v)) {
                                if (isset($v['default'])) return is_numeric($v['default']) ? 0 + $v['default'] : $v['default'];
                                if (isset($v['value']))   return is_numeric($v['value'])   ? 0 + $v['value']   : $v['value'];
                            } else {
                                return is_numeric($v) ? 0 + $v : $v;
                            }
                        }
                        return null;
                    };

                    $name     = $root['display_name'] ?? $root['displayName'] ?? $root['name'] ?? $programName;
                    $duration = $readParam($params, ['duration','durationMM','durationMin']);
                    $temp     = $readParam($params, ['temperature','temp']);
                  
                  
                  // ✅ Appliquer la spec temp (slider/select) pour ce programme
if (method_exists($eqLogic, 'applyTempSpecForProgram')) {
    $eqLogic->applyTempSpecForProgram($programName);
}

// ✅ Mettre la température du programme dans desired_temp (et donc dans le widget)
$desired = $eqLogic->getCmd(null, 'desired_temp');
if (is_object($desired)) {
    if ($temp !== null && $temp !== '') {
        $t = is_numeric($temp) ? (0 + $temp) : $temp;

        // Normaliser si possible (au cas où temp n'est pas exactement dans la spec)
        if (is_numeric($t) && method_exists($eqLogic, 'getTempSpecForProgram') && method_exists($eqLogic, 'normalizeTempToSpec')) {
            $spec = $eqLogic->getTempSpecForProgram($programName);
            if ($spec) $t = $eqLogic->normalizeTempToSpec($t, $spec);
        }

        $desired->event($t);
        log::add('hon', 'info', "Température programme → desired_temp = {$t}");
    } else {
        // pas de temp trouvée => on laisse vide (temp standard)
        $desired->event('');
    }
}
                  
                  

                    $spin     = $readParam($params, ['spinSpeed','spin']);
                    $autoDet  = $readParam($params, ['autoDetergentStatus','autoDetergent']);
                    $autoSoft = $readParam($params, ['autoSoftenerStatus','autoSoftener']);

                    $lines[] = $name;
             if ($temp !== null && $temp !== '') {

    $tempLine = 'Temp : ' . $temp . '°C';

    // Ajouter les températures possibles entre parenthèses
    if (method_exists($eqLogic, 'getTempSpecForProgram')) {
        $spec = $eqLogic->getTempSpecForProgram($programName);

        if (is_array($spec)) {
            if (($spec['kind'] ?? '') === 'range') {
                $min  = $spec['min'];
                $max  = $spec['max'];
                $step = $spec['step'] ?? 1;

                $fmt = function($n) {
                    return (is_numeric($n) && floor($n) == $n) ? (string)(int)$n : (string)$n;
                };

                $tempLine .= ' (' .
                             $fmt($min) . ' à ' . $fmt($max) . ', pas ' . $fmt($step) .
                             ')';

            } elseif (($spec['kind'] ?? '') === 'enum' && !empty($spec['values'])) {
                $vals = array_map(function($v){
                    return (is_numeric($v) && floor($v) == $v) ? (string)(int)$v : (string)$v;
                }, $spec['values']);

                $tempLine .= ' (' . implode(', ', $vals) . ')';
            }
        }
    }

    $lines[] = $tempLine;
}

                  
                  
                  
                  
                  
                  
                  
                  
                    if ($spin !== null && $spin !== '')         $lines[] = 'Essorage : ' . $spin . ' rpm';
                    if ($duration !== null && $duration !== '') $lines[] = 'Durée : ' . $duration . ' min';
                    if ($autoDet !== null && $autoDet !== '')   $lines[] = ((string)$autoDet === "1" || strtolower((string)$autoDet) === "true") ? "Auto lessive : activé" : "Auto lessive : désactivé";
                    if ($autoSoft !== null && $autoSoft !== '') $lines[] = ((string)$autoSoft === "1" || strtolower((string)$autoSoft) === "true") ? "Auto adoucissant : activé" : "Auto adoucissant : désactivé";
                }



            } catch (Exception $e) {
                log::add('hon', 'error', 'Erreur lecture JSON programme : ' . $e->getMessage());
            }

            if (empty($lines)) {
                $lines[] = $programName;
                $grab = function($id) use ($eqLogic) {
                    $c = $eqLogic->getCmd(null, $id);
                    if (is_object($c)) { return trim((string)$c->execCmd()); }
                    return null;
                };
                $temp     = $grab('temp');
                $spin     = $grab('spinSpeed');
                $dur      = $grab('remainingTimeMM');
                $autoDet  = $grab('autoDetergentStatus');
                $autoSoft = $grab('autoSoftenerStatus');

                if ($temp !== null && $temp !== '')  $lines[] = 'Température : ' . $temp . '°C';
                if ($spin !== null && $spin !== '')  $lines[] = 'Essorage : ' . $spin . ' rpm';
                if ($dur  !== null && $dur  !== '')  $lines[] = 'Durée : ' . $dur . ' min';
                if ($autoDet !== null && $autoDet !== '')  $lines[] = ((string)$autoDet === "1") ? "Auto lessive : activé" : "Auto lessive : désactivé";
                if ($autoSoft !== null && $autoSoft !== '') $lines[] = ((string)$autoSoft === "1") ? "Auto adoucissant : activé" : "Auto adoucissant : désactivé";

            }

            $infoSummary = $eqLogic->getCmd(null, 'selectedProgramInfo');
           // if (is_object($infoSummary)) $infoSummary->event(implode('<br>', $lines));
          if (is_object($infoSummary)) $infoSummary->event(implode("\n", $lines));

            log::add('hon', 'info', '=== Fin sélection programme ===');

        } else {
            log::add('hon', 'warning', 'Commande non reconnue : ' . $logicalId);
        }

        if (in_array($logicalId, ['stop', 'pause', 'resume', 'start_selected'])) {
            $eqLogic->scheduleRefresh(3);
        }
    }
}

?>
