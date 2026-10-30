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

    /**
     * Interroge hOn (hon_cron_updater.py) et met à jour les commandes info.
     * Sans argument : tous les équipements actifs non rafraîchis depuis 45 s (cron).
     * Avec un équipement : cet équipement uniquement, immédiatement (bouton Rafraîchir).
     */
    public static function refreshAllDevices($_eqLogic = null) {
        try {
            $eqLogics = ($_eqLogic === null) ? eqLogic::byType('hon', true) : [$_eqLogic];
            if (!is_array($eqLogics) || empty($eqLogics)) return;

            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'warning', 'Tokens non disponibles pour le rafraîchissement');
                return;
            }

            // Push actif : le cron ne sert plus que de filet de sécurité (toutes les 10 min)
            $minAge = 45;
            if ($_eqLogic === null) {
                $daemon = self::deamon_info();
                if ($daemon['state'] == 'ok') $minAge = 600;
            }

            $devices = [];
            foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getIsEnable()) {
                    $lastRefresh = (int)$eqLogic->getConfiguration('lastRefresh', 0);
                    $currentTime = time();
                    if ($_eqLogic !== null || ($currentTime - $lastRefresh) >= $minAge) {
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

    /* ========================= Dépendances ========================= */

    /** Python du venv du plugin (installé par les dépendances), sinon python3 du système */
    public static function python() {
        $venv = realpath(__DIR__ . '/../../resources') . '/venv/bin/python3';
        return file_exists($venv) ? escapeshellarg($venv) : 'python3';
    }

    public static function dependancy_info() {
        $return = [
            'log'           => __CLASS__ . '_update',
            'progress_file' => jeedom::getTmpFolder(__CLASS__) . '/dependency',
            'state'         => 'nok',
        ];
        $venv = __DIR__ . '/../../resources/venv/bin/python3';
        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
        } elseif (file_exists($venv)) {
            exec(escapeshellarg($venv) . ' -c "import aiohttp" 2>&1', $out, $code);
            if ($code === 0) $return['state'] = 'ok';
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return [
            'script' => __DIR__ . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency',
            'log'    => log::getPathToLog(__CLASS__ . '_update'),
        ];
    }

    /* ========================= Démon push ========================= */

    public static function deamon_info() {
        $return = ['log' => 'hon', 'state' => 'nok', 'launchable' => 'ok'];
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pidFile)) {
            if (@posix_getsid(intval(trim(file_get_contents($pidFile))))) {
                $return['state'] = 'ok';
            } else {
                @unlink($pidFile);
            }
        }
        if (self::dependancy_info()['state'] != 'ok') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Dépendances non installées', __FILE__);
        } elseif (config::byKey('email', __CLASS__) == '' || config::byKey('password', __CLASS__) == '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Email et mot de passe hOn non configurés', __FILE__);
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $info = self::deamon_info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $script = realpath(__DIR__ . '/../../resources/hond/hond.py');
        $cmd = self::python() . ' -u ' . escapeshellarg($script)
             . ' --loglevel ' . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)))
             . ' --callback ' . escapeshellarg(network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/hon/core/php/jeeHon.php')
             . ' --apikey ' . escapeshellarg(jeedom::getApiKey(__CLASS__))
             . ' --pid ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        log::add(__CLASS__, 'info', 'Lancement du démon push hOn');
        exec($cmd . ' >> ' . log::getPathToLog('hon') . ' 2>&1 &');

        for ($i = 0; $i < 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') return true;
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Impossible de lancer le démon push, consultez le log hon', __FILE__), 'unableStartDeamon');
        return false;
    }

    public static function deamon_stop() {
        $pidFile = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pidFile)) {
            $pid = intval(trim(file_get_contents($pidFile)));
            if ($pid > 0) posix_kill($pid, 15);
            sleep(1);
            @unlink($pidFile);
        }
        system::kill('hond.py');
    }

    /** Configuration envoyée au démon : jetons + appareils et sujets MQTT */
    public static function getDaemonConfig() {
        $tokens = self::getCachedTokens();
        if (!$tokens && self::refreshTokens()) $tokens = self::getCachedTokens();
        if (!$tokens) return ['error' => 'jetons hOn indisponibles'];

        $dataDir = config::byKey('dataDir', __CLASS__, __DIR__ . '/../../resources/hon/data');
        $devices = [];
        foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
            $mac = str_replace(':', '-', $eqLogic->getConfiguration('macAddress', ''));
            if ($mac === '') continue;
            $topics = [];
            $deviceFile = $dataDir . '/device_' . $mac . '.json';
            if (file_exists($deviceFile)) {
                $json   = json_decode(file_get_contents($deviceFile), true);
                $topics = $json['device_identification']['raw_appliance_data']['topics']['subscribe'] ?? [];
            }
            if (empty($topics)) {
                $topics = [
                    'haier/things/' . $mac . '/event/appliancestatus/update',
                    '$aws/events/presence/connected/' . $mac,
                    '$aws/events/presence/disconnected/' . $mac,
                ];
            }
            $devices[] = [
                'mac'    => $mac,
                'type'   => self::getApplianceTypeCode($eqLogic->getConfiguration('applianceType')),
                'topics' => array_values($topics),
            ];
        }
        return [
            'id_token'      => $tokens['id_token'],
            'cognito_token' => $tokens['cognito_token'],
            'mobile_id'     => $tokens['mobile_id'] ?? '',
            'devices'       => $devices,
        ];
    }

    /** Mise à jour reçue du démon push (même format que le cron) */
    public static function pushUpdate($payload) {
        log::add(__CLASS__, 'debug', 'Push reçu : ' . json_encode($payload));

        // État avant mise à jour (pour détecter un démarrage de cycle)
        $mac     = $payload['mac_address'];
        $eqLogic = self::byLogicalId($mac, __CLASS__) ?: self::byLogicalId(str_replace('-', ':', $mac), __CLASS__);
        $stateCmd = is_object($eqLogic) ? $eqLogic->getCmd(null, 'machine_state') : null;
        $before   = is_object($stateCmd) ? $stateCmd->execCmd() : '';

        self::updateDeviceFromQuickDataWithTranslation($payload);

        // Le push n'envoie que ce qui change : au démarrage d'un cycle, lecture complète
        // pour récupérer les valeurs identiques au cycle précédent (température, etc.)
        $after = $payload['data']['status']['value'] ?? null;
        if (is_object($eqLogic) && $after === 'En cours' && $before !== 'En cours') {
            log::add(__CLASS__, 'debug', 'Début de cycle détecté → lecture complète de ' . $eqLogic->getName());
            self::refreshAllDevices($eqLogic);
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

            log::add('hon', 'debug', 'Tokens valides (âge: ' . round($tokenAge/3600, 1) . 'h)');

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
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && ' . self::python() . ' hon_cron_updater.py ' .
                       escapeshellarg($device['mac_address']) . ' ' .
                       escapeshellarg($device['appliance_type']) . ' ' .
                       escapeshellarg($tokens['id_token']) . ' ' .
                       escapeshellarg($tokens['cognito_token']);
            } else {
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && ' . self::python() . ' hon_cron_updater.py ' .
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
            // Sortie du script en debug, sans les jetons
            log::add('hon', 'debug', 'Sortie complète script : ' . preg_replace('/"(updated_cognito_token|cognito_token)":\s*"[^"]*"/', '"$1": "***"', $output));

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
            $cmd1    = 'cd ' . escapeshellarg($resourcesDir) . ' && ' . self::python() . ' hon_json_generator_programme.py ' .
                       escapeshellarg($email) . ' ' . escapeshellarg($password) . ' 2>&1';
            $output1 = shell_exec($cmd1);
            log::add('hon', 'debug', 'Sortie génération programmes : ' . $output1);

            log::add('hon', 'info', 'Génération des JSONs des informations des appareils...');
            $cmd2    = 'cd ' . escapeshellarg($resourcesDir) . ' && ' . self::python() . ' hon_json_generator_device.py ' .
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
        $cmd    = 'cd ' . escapeshellarg(__DIR__ . '/../../resources') . ' && ' . self::python() . ' hon_get_tokens.py ' .
                  escapeshellarg($email) . ' ' . escapeshellarg($password);
        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie tokens : ' . preg_replace('/"(id_token|cognito_token)":\s*"[^"]*"/', '"$1": "***"', $output));
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
        $this->createEssentialActionCommands();
        $this->createSelectionWorkflowCommands($dataDir);
        $this->buildPrCodeMap($dataDir);
        $this->save();

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
            'autoSoftenerStatus'     => ['name' => 'Auto adoucissant',        'subtype' => 'binary'],
            'detWarn'                => ['name' => 'Alerte lessive',          'subtype' => 'binary'],   // 0 = OK, 1 = alarme
            'softWarn'               => ['name' => 'Alerte adoucissant',      'subtype' => 'binary']    // 0 = OK, 1 = alarme
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
            // Corrige le sous-type d'une commande déjà créée (ex. alertes passées de numérique à binaire)
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd) && $cmd->getSubType() != $cfg['subtype']) {
                $cmd->setSubType($cfg['subtype']);
                $cmd->save();
            }
        }
        log::add('hon', 'info', count($info) . ' commandes info créées pour ' . $this->getName());
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

    /** Rafraîchit immédiatement cet équipement depuis hOn */
    public function refreshInfo() {
        self::refreshAllDevices($this);
    }

    /**
     * Rafraîchissements différés en arrière-plan (sans bloquer Jeedom),
     * le temps que la machine remonte son nouvel état à hOn.
     */
    public function scheduleRefresh($delays = [10]) {
        // Push actif : hOn envoie lui-même le nouvel état, inutile d'aller le chercher
        if (self::deamon_info()['state'] == 'ok') return;
        $script = __DIR__ . '/../php/honRefresh.php';
        $args   = implode(' ', array_map('intval', (array)$delays));
        exec('nohup php ' . escapeshellarg($script) . ' ' . intval($this->getId()) . ' ' . $args . ' > /dev/null 2>&1 &');
        log::add('hon', 'debug', 'Rafraîchissements programmés à +' . str_replace(' ', ' s, +', $args) . ' s pour ' . $this->getName());
    }

    /**
     * Lance un programme ou envoie stop / pause / resume.
     * Envoi direct en PHP avec les jetons en cache (~0,5 s) ;
     * en secours, l'ancien script Python (reconnexion complète, ~5 s).
     */
    public function launchProgram($programName, $parameters = []) {
        $start  = microtime(true);
        $result = $this->sendDirectCommand($programName, $parameters);
        if ($result !== null) {
            log::add('hon', 'debug', 'Envoi direct ' . $programName . ' : ' . ($result ? 'OK' : 'ÉCHEC')
                . ' en ' . round((microtime(true) - $start) * 1000) . ' ms');
            return $result;
        }
        log::add('hon', 'info', 'Envoi direct impossible → script Python');
        return $this->launchProgramPython($programName, $parameters);
    }

    /**
     * Construit et envoie la commande à hOn.
     * Retourne true/false selon la réponse de hOn, ou null si l'envoi direct n'est pas possible
     * (jetons ou fichiers de synchro absents) → le script Python prend le relais.
     */
    private function sendDirectCommand($programName, $parameters = []) {
        $tokens = self::getCachedTokens();
        if (!$tokens) return null;

        $dataDir = $this->getDataDir();
        $mac     = $this->getMacFilename();
        $typeName = $this->getConfiguration('applianceType', 'WM');

        if (in_array($programName, ['stop', 'pause', 'resume'])) {
            // Commande officielle hOn de l'appareil (device_<mac>.json), sinon ancien format
            $official = ['stop' => 'stopProgram', 'pause' => 'pauseProgram', 'resume' => 'resumeProgram'][$programName];
            $fallback = [
                'stop'   => ['machMode' => '3', 'onOffStatus' => '1'],
                'pause'  => ['pause' => '1'],
                'resume' => ['pause' => '0'],
            ][$programName];
            $commandName = 'startProgram';
            $params      = $fallback;
            $deviceFile  = $dataDir . '/device_' . $mac . '.json';
            if (file_exists($deviceFile)) {
                $device = json_decode(file_get_contents($deviceFile), true);
                $def    = $device['commands']['raw_commands_data'][$official] ?? null;
                if (is_array($def)) {
                    $commandName = $official;
                    $params      = [];
                    foreach (($def['parameters'] ?? []) as $name => $info) {
                        $value = $info['fixedValue'] ?? $info['defaultValue'] ?? null;
                        if ($value !== null) $params[$name] = (string)$value;
                    }
                }
            }
        } else {
            // Programme : valeurs par défaut du JSON + paramètres choisis (uniquement ceux du programme)
            $file = $dataDir . '/' . $mac . '_' . $programName . '.json';
            if (!file_exists($file)) return null;
            $json = json_decode(file_get_contents($file), true);
            $info = $json['program_info'] ?? null;
            if (!is_array($info) || empty($info['default_parameters'])) return null;
            $typeName    = $json['device_info']['type_name'] ?? $typeName;
            $commandName = 'startProgram';
            $params      = $info['default_parameters'];
            foreach ((array)$parameters as $key => $value) {
                if (isset($info['parameters'][$key])) $params[$key] = (string)$value;
                else log::add('hon', 'warning', 'Paramètre ' . $key . ' ignoré (absent du programme ' . $programName . ')');
            }
        }

        log::add('hon', 'debug', 'Commande hOn ' . $commandName . ' : ' . json_encode($params));
        $response = self::postHonCommand($mac, $typeName, $commandName, $params, $tokens);
        if ($response === 401 && self::refreshTokens()) {
            $response = self::postHonCommand($mac, $typeName, $commandName, $params, self::getCachedTokens());
        }
        if ($response === null || is_int($response)) return null;      // réseau / HTTP → secours Python
        if (($response['payload']['resultCode'] ?? '') === '0') return true;
        log::add('hon', 'warning', 'Commande refusée par hOn : ' . json_encode($response));
        return false;
    }

    public static $commandUrl = 'https://api-iot.he.services/commands/v1/send';

    /** POST /commands/v1/send — retourne la réponse décodée, le code HTTP en cas d'erreur, ou null */
    private static function postHonCommand($mac, $typeName, $commandName, $params, $tokens) {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $body = [
            'macAddress'          => $mac,
            'timestamp'           => $timestamp,
            'commandName'         => $commandName,
            'transactionId'       => $mac . '_' . $timestamp,
            'applianceOptions'    => new stdClass(),
            'device'              => [
                'mobileId'    => $tokens['mobile_id'] ?: bin2hex(random_bytes(8)),
                'mobileOs'    => 'android',
                'osVersion'   => 31,
                'appVersion'  => '2.0.10',
                'deviceModel' => 'exynos9820',
            ],
            'attributes'          => ['channel' => 'mobileApp', 'origin' => 'standardProgram', 'energyLabel' => '0'],
            'ancillaryParameters' => ['programFamily' => '[standard]', 'remoteActionable' => '1', 'remoteVisible' => '1'],
            'parameters'          => $params,
            'applianceType'       => $typeName,
        ];
        $ch = curl_init(self::$commandUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'cognito-token: ' . $tokens['cognito_token'],
                'id-token: ' . $tokens['id_token'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            log::add('hon', 'warning', 'Envoi hOn impossible : ' . $err);
            return null;
        }
        if ($code != 200) {
            log::add('hon', 'warning', 'hOn a répondu HTTP ' . $code . ' : ' . substr($raw, 0, 200));
            return $code;
        }
        return json_decode($raw, true);
    }

    /** Ancien envoi via hon_launcher.py (secours) */
    private function launchProgramPython($programName, $parameters = []) {
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
            $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && ' . self::python() . ' hon_launcher.py ' .
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

            return $success;

        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lancement programme : ' . $e->getMessage());
            return false;
        }
    }

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

    /* ========================= Traductions ========================= */

    /** Niveaux de séchage hOn (sèche-linge) */
    private static $dryLevels = [
        0  => 'Aucun',
        11 => 'Minuterie',
        12 => 'Prêt à repasser (H-1)',
        13 => 'Prêt à ranger (H-2)',
        14 => 'Très sec (H-3)',
    ];

    public static function translateDryLevel($dryLevel) {
        return self::$dryLevels[(int)$dryLevel] ?? 'Niveau ' . $dryLevel;
    }

    /**
     * Nom du programme en cours à partir de son prCode.
     * Plusieurs programmes partagent un même prCode (ex. 115 = Coton et plusieurs programmes hOn) :
     * priorité au programme sélectionné dans Jeedom, puis au programme natif de la machine.
     */
    public function getProgramNameFromCode($prCode) {
        if ($prCode === '' || $prCode === null || (string)$prCode === '0') return '';
        $map = $this->getConfiguration('prCodeMap', []);
        if (!is_array($map) || empty($map)) $map = $this->buildPrCodeMap();
        $candidates = $map[(string)$prCode] ?? [];
        if (empty($candidates)) return 'Programme ' . $prCode;

        $info     = $this->getCmd(null, 'selectedProgram');
        $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
        if ($selected !== '' && in_array($selected, $candidates)) return self::getProgramLabel($selected);
        return self::getProgramLabel($candidates[0]);
    }

    /** Construit (à la synchro) la table prCode → programmes, natifs en premier */
    private function buildPrCodeMap($dataDir = null) {
        $map = [];
        foreach (array_keys($this->getAvailablePrograms($dataDir)) as $name) {
            $pars = $this->getProgramParameters($name, $dataDir) ?? [];
            $code = $pars['prCode']['value'] ?? $pars['prCode']['default'] ?? null;
            if ($code !== null) $map[(string)$code][] = $name;
        }
        $this->setConfiguration('prCodeMap', $map);
        return $map;
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
                'estimated_end_time'       => 'estimated_end_time',
                'det_warn'                 => 'detWarn',
                'soft_warn'                => 'softWarn'
            ];

            foreach ($data as $pythonKey => $valueData) {
                if (!isset($keyMapping[$pythonKey])) continue;
                $cmdLogicalId = $keyMapping[$pythonKey];
                $cmd          = $eqLogic->getCmd(null, $cmdLogicalId);
                if (!is_object($cmd)) continue;

                $newValue = $valueData['value'];

                if ($pythonKey === 'program_code') {
                    $translatedCmd = $eqLogic->getCmd(null, 'prCodeTranslated');
                    $translated    = $eqLogic->getProgramNameFromCode($newValue);
                } elseif ($pythonKey === 'dry_level') {
                    $translatedCmd = $eqLogic->getCmd(null, 'dryLevelTranslated');
                    $translated    = self::translateDryLevel($newValue);
                } else {
                    $translatedCmd = null;
                }
                if (is_object($translatedCmd) && $translatedCmd->execCmd() != $translated) {
                    $translatedCmd->event($translated);
                    $hasUpdates = true;
                }

                $currentValue = $cmd->execCmd();
                if (is_bool($newValue)) $newValue = $newValue ? 1 : 0;
                if ($currentValue != $newValue) {
                    $cmd->event($newValue);
                    $hasUpdates = true;
                }
            }

            // Machine à l'arrêt : elle publie la durée du programme affiché sur son écran
            // (temps restant, lavage principal) sans qu'aucun cycle ne tourne → on affiche 0
            $stateCmd = $eqLogic->getCmd(null, 'machine_state');
            $state    = $data['status']['value'] ?? (is_object($stateCmd) ? $stateCmd->execCmd() : '');
            if ($state !== '' && !in_array($state, ['En cours', 'Pause', 'Programmé'])) {
                foreach (['remainingTimeMM' => 0, 'remainingMainWashTime' => 0, 'estimated_end_time' => ''] as $id => $idle) {
                    $c = $eqLogic->getCmd(null, $id);
                    if (is_object($c) && $c->execCmd() != $idle) {
                        $c->event($idle);
                        $hasUpdates = true;
                    }
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
                // Température et essorage gardent la valeur de la machine (programme affiché) :
                // le push ne les renvoie que s'ils changent, une remise à 0 resterait bloquée
                $wmToReset = [
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

    /* ========================= Programmes & paramètres ========================= */

    /**
     * Paramètres modifiables proposés à l'utilisateur, par type d'appareil.
     * Clé = nom du paramètre hOn. Pour chacun, le plugin crée :
     *   - une action select  "set_<param>"     (liste adaptée au programme choisi)
     *   - une info           "desired_<param>" (valeur choisie, envoyée au démarrage)
     * Pour ajouter un paramètre (ex. rinseIterations, delayTime), il suffit de l'ajouter ici.
     */
    private static $programParams = [
        'WM' => [
            'temp'                => ['name' => 'Température',       'unit' => '°C'],
            'mainWashTime'        => ['name' => 'Temps de lavage',   'unit' => 'min', 'widget' => 'slider'],
            'spinSpeed'           => ['name' => 'Essorage',          'unit' => 'tr/min'],
            'autoSoftenerStatus'  => ['name' => 'Adoucissant auto',  'unit' => ''],
            'autoDetergentStatus' => ['name' => 'Lessive auto',      'unit' => ''],
        ],
        'TD' => [
            'tempLevel'           => ['name' => 'Température',       'unit' => ''],
            'dryTimeMM'           => ['name' => 'Temps de séchage',  'unit' => 'min'],
            'dryLevel'            => ['name' => 'Niveau de séchage', 'unit' => ''],
        ],
    ];

    /** Noms français des programmes (clé = nom sans préfixe hqd_ / iot_wash_ / iot_dry_) */
    private static $programLabels = [
        // Lave-linge
        '20_degrees' => '20°C', 'allergy' => 'Anti-allergie', 'autoclean' => 'Auto-nettoyage tambour',
        'babycare' => 'Soin bébé', 'checkup' => 'Diagnostic', 'cottons' => 'Coton',
        'delicate_cradle' => 'Délicat', 'eco_40_60_degrees' => 'Éco 40-60', 'handwash_wool' => 'Laine / Lavage main',
        'quick_15' => 'Rapide 15 min', 'quick_wash_57' => 'Rapide 57 min', 'rinse' => 'Rinçage',
        'smart' => 'Smart', 'spin' => 'Essorage', 'synthetic_and_coloured' => 'Synthétique & couleurs',
        'anti_mites' => 'Anti-acariens', 'anti_odor' => 'Anti-odeurs',
        'ariel_clean_cycle' => 'Ariel cycle propre', 'ariel_cold_cycle' => 'Ariel cycle froid', 'ariel_fresh_cycle' => 'Ariel cycle fraîcheur',
        'dash_clean_cycle' => 'Dash cycle propre', 'dash_cold_cycle' => 'Dash cycle froid', 'dash_fresh_cycle' => 'Dash cycle fraîcheur',
        'baby_sanitizer' => 'Hygiène bébé', 'bleaching' => 'Blanchiment', 'blood_stains' => 'Taches de sang',
        'cashmere' => 'Cachemire', 'chocolate_stains' => 'Taches de chocolat', 'cold_wash' => 'Lavage à froid',
        'colored' => 'Couleurs', 'coloured' => 'Linge coloré', 'colored_anti_stain' => 'Couleurs anti-taches',
        'colored_delicate' => 'Couleurs délicates', 'dark' => 'Linge foncé', 'delicate_antiallergy' => 'Délicat anti-allergie',
        'delicate_colors' => 'Délicat couleurs', 'delicate_dark' => 'Délicat foncé', 'delicate_tablecloths' => 'Nappes délicates',
        'delicate_whites' => 'Délicat blanc', 'diving_suits' => 'Combinaisons de plongée', 'down_jackets' => 'Doudounes',
        'fruit_stains' => 'Taches de fruits', 'handwash' => 'Lavage à la main', 'handwash_colored' => 'Lavage main couleurs',
        'handwash_dark' => 'Lavage main foncé', 'masks_refresh' => 'Masques rafraîchissement', 'masks_sanification' => 'Masques désinfection',
        'mats' => 'Tapis', 'men_s_trousers' => 'Pantalons homme', 'new_clothes' => 'Vêtements neufs',
        'perfect_white' => 'Blanc parfait', 'pets' => 'Animaux', 'pets_hair_removal' => 'Poils d\'animaux',
        'pets_odours_stains_removal' => 'Odeurs et taches d\'animaux', 'quick_drum_cleaner' => 'Nettoyage tambour rapide',
        'rapid_14' => 'Rapide 14 min', 'rapid_44' => 'Rapide 44 min',
        'resistant_colored' => 'Couleurs résistantes', 'resistant_dark' => 'Foncé résistant', 'resistant_whites' => 'Blanc résistant',
        'ski_suit' => 'Combinaisons de ski', 'synthetic' => 'Synthétique', 'trainers' => 'Baskets',
        'whites' => 'Blanc', 'wine_stains' => 'Taches de vin', 'technical_jackets' => 'Vestes techniques',
        // Communs lave-linge / sèche-linge
        'backpacks' => 'Sacs à dos', 'bathrobe' => 'Peignoirs', 'bed_linen' => 'Draps', 'cotton' => 'Coton',
        'cuddly_toys' => 'Peluches', 'curtains' => 'Rideaux', 'curtain' => 'Rideaux', 'delicate' => 'Délicat',
        'delicates' => 'Délicat', 'denim_jeans' => 'Jeans', 'jeans' => 'Jeans', 'duvet' => 'Couette',
        'lingerie' => 'Lingerie', 'mix' => 'Mixte', 'mixed' => 'Mixte', 'rapid_30' => 'Rapide 30 min',
        'rapid_59' => 'Rapide 59 min', 'refresh' => 'Rafraîchissement', 'shirts' => 'Chemises', 'shirt' => 'Chemises',
        'silk' => 'Soie', 'sport' => 'Sport', 'sports' => 'Sport', 'swimsuits_and_bikinis' => 'Maillots de bain',
        'synthetics' => 'Synthétique', 'tablecloths' => 'Nappes', 'technical_fabrics' => 'Tissus techniques',
        'tennis_tie_break' => 'Tennis', 'wool' => 'Laine',
        // Sèche-linge
        'baby_care' => 'Bébé', 'baby' => 'Bébé', 'bath_towel' => 'Serviettes de bain', 'bed_sheets' => 'Draps',
        'bulky' => 'Volumineux', 'casual' => 'Quotidien', 'cold_wind_timing' => 'Air froid minuté',
        'hot_wind_timing' => 'Air chaud minuté', 'feather' => 'Plumes', 'hygienic' => 'Hygiène',
        'i_refresh' => 'i-Refresh', 'jacket' => 'Vestes', 'night_dry' => 'Séchage de nuit', 'outdoor' => 'Outdoor',
        'precious_cure' => 'Soin précieux', 'quick_30' => 'Rapide 30 min', 'quick_dry' => 'Séchage rapide',
        'quilt' => 'Édredon', 'shoes' => 'Chaussures', 'timer' => 'Minuterie', 'towel' => 'Serviettes',
        'underwear' => 'Sous-vêtements', 'working_suit' => 'Vêtements de travail', 'down_jacket' => 'Doudounes',
        'gym_fit' => 'Fitness', 'pet_accessories' => 'Accessoires animaux', 'pet_hair_removal' => 'Poils d\'animaux',
    ];

    /** Libellé français d'un programme (les programmes téléchargeables hOn sont suffixés "(hOn)") */
    public static function getProgramLabel($programName) {
        $isIot = (strpos($programName, 'iot_') === 0);
        $key   = preg_replace('/^(hqd_|iot_wash_|iot_dry_|iot_)/', '', $programName);
        $label = self::$programLabels[$key] ?? ucfirst(str_replace('_', ' ', $key));
        return $isIot ? $label . ' (hOn)' : $label;
    }

    /** Paramètres gérés pour cet équipement (selon WM / TD / WD) */
    public function getManagedParams() {
        $code = self::getApplianceTypeCode($this->getConfiguration('applianceType', ''));
        switch ($code) {
            case 'TD': return self::$programParams['TD'];
            case 'WD': return array_merge(self::$programParams['WM'], self::$programParams['TD']);
            default:   return self::$programParams['WM'];
        }
    }

    private function getDataDir() {
        return config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
    }

    private function getMacFilename() {
        $macFilename = $this->getConfiguration('mac_filename', '');
        if ($macFilename === '') $macFilename = str_replace(':', '-', $this->getConfiguration('macAddress', ''));
        return $macFilename;
    }

    /** Liste des programmes disponibles : [nom_api => libellé], natifs puis hOn, triés par libellé */
    public function getAvailablePrograms($dataDir = null) {
        if ($dataDir === null) $dataDir = $this->getDataDir();
        $macFilename = $this->getMacFilename();
        $native = [];
        $iot    = [];
        foreach ((array)glob($dataDir . '/' . $macFilename . '_*.json') as $file) {
            $name = substr(basename($file, '.json'), strlen($macFilename) + 1);
            if ($name === '' || strpbrk($name, '|;') !== false) continue;
            if (strpos($name, 'iot_') === 0) $iot[$name]    = self::getProgramLabel($name);
            else                              $native[$name] = self::getProgramLabel($name);
        }
        asort($native, SORT_STRING | SORT_FLAG_CASE);
        asort($iot,    SORT_STRING | SORT_FLAG_CASE);
        return $native + $iot;
    }

    /** Lit le JSON d'un programme et renvoie son bloc "parameters" (ou null) */
    public function getProgramParameters($programName, $dataDir = null) {
        if ($programName === '' || preg_match('/[^a-zA-Z0-9_]/', $programName)) return null;
        $path = ($dataDir ?? $this->getDataDir()) . '/' . $this->getMacFilename() . '_' . $programName . '.json';
        if (!file_exists($path)) {
            log::add('hon', 'warning', 'JSON programme introuvable : ' . basename($path));
            return null;
        }
        $json = json_decode(@file_get_contents($path), true);
        if (!is_array($json)) {
            log::add('hon', 'warning', 'JSON invalide : ' . basename($path));
            return null;
        }
        $root = $json['program_info'] ?? $json['program'] ?? $json;
        return $root['parameters'] ?? null;
    }

    /**
     * Spécification d'un paramètre pour un programme :
     *   ['fixed' => bool, 'values' => [..], 'default' => v]   ou null si absent du programme
     */
    public static function getParamSpec($paramDef) {
        if (!is_array($paramDef) || !isset($paramDef['type'])) return null;
        $num = function ($v) { return is_numeric($v) ? 0 + $v : $v; };

        switch (strtolower($paramDef['type'])) {
            case 'fixed':
                $v = $num($paramDef['value'] ?? '');
                return ['fixed' => true, 'values' => [$v], 'default' => $v];

            case 'enum':
                $values = array_values(array_unique(array_map($num, (array)($paramDef['values'] ?? []))));
                if (empty($values)) return null;
                $default = isset($paramDef['default']) ? $num($paramDef['default']) : $values[0];
                return ['fixed' => (count($values) == 1), 'values' => $values, 'default' => $default];

            case 'range':
                $min  = floatval($paramDef['min'] ?? 0);
                $max  = floatval($paramDef['max'] ?? $min);
                $step = floatval($paramDef['step'] ?? 1);
                if ($step <= 0) $step = 1;
                $values = [];
                for ($v = $min; $v <= $max + 1e-9 && count($values) < 200; $v += $step) $values[] = $num(round($v, 2));
                if (empty($values)) return null;
                $default = isset($paramDef['default']) ? $num($paramDef['default']) : $values[0];
                return ['fixed' => (count($values) == 1), 'values' => $values, 'default' => $default,
                        'min' => $num($min), 'max' => $num(end($values)), 'step' => $num($step)];
        }
        return null;
    }

    /** Ramène une valeur sur la valeur autorisée la plus proche */
    public static function normalizeToSpec($value, $spec) {
        if (!is_array($spec) || empty($spec['values'])) return $value;
        if (in_array($value, $spec['values'])) return $value;
        if (!is_numeric($value)) return $spec['default'];
        $best = $spec['default']; $bestD = PHP_FLOAT_MAX;
        foreach ($spec['values'] as $cand) {
            if (!is_numeric($cand)) continue;
            $d = abs($value - $cand);
            if ($d < $bestD) { $bestD = $d; $best = $cand; }
        }
        return $best;
    }

    /** Libellé lisible d'une valeur de paramètre */
    public function formatParamValue($param, $value) {
        if ($value === '' || $value === null) return '';
        $v = is_numeric($value) ? 0 + $value : $value;
        switch ($param) {
            case 'temp':                return ($v == 0) ? 'Froid' : $v . ' °C';
            case 'spinSpeed':           return ($v == 0) ? 'Sans essorage' : $v . ' tr/min';
            case 'mainWashTime':        return $v . ' min';
            case 'dryTimeMM':           return ($v == 0) ? 'Auto (selon le niveau de séchage)' : $v . ' min';
            case 'autoSoftenerStatus':
            case 'autoDetergentStatus': return ($v == 1) ? 'Oui' : 'Non';
            case 'tempLevel':
                $t = [1 => 'Froid', 2 => 'Basse (L-1)', 3 => 'Moyenne (L-2)', 4 => 'Haute (L-3)'];
                return $t[$v] ?? 'Niveau ' . $v;
            case 'dryLevel':            return self::translateDryLevel($v);
        }
        $unit = $this->getManagedParams()[$param]['unit'] ?? '';
        return trim($v . ' ' . $unit);
    }

    /** Nom de commande libre sur l'équipement (Jeedom interdit deux commandes du même nom) */
    private function freeCmdName($name) {
        $used = [];
        foreach ($this->getCmd() as $c) $used[] = mb_strtolower(trim($c->getName()));
        $candidate = $name;
        for ($i = 2; in_array(mb_strtolower($candidate), $used); $i++) $candidate = $name . ' ' . $i;
        return $candidate;
    }

    /** Crée ou met à jour une commande action select (ou slider) liée à une info */
    private function upsertSelectCommand($logicalId, $name, $listValue, $infoLogicalId = null, $order = null, $subType = 'select') {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new honCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName($this->freeCmdName($name));
            $cmd->setIsVisible(1);
            if ($order !== null) $cmd->setOrder($order);
        }
        $cmd->setType('action');
        $cmd->setSubType($subType);
        $cmd->setConfiguration('listValue', $listValue);
        if ($subType == 'select') {
            $cmd->setConfiguration('minValue', null);
            $cmd->setConfiguration('maxValue', null);
            $cmd->setConfiguration('step', null);
        }
        if ($infoLogicalId !== null) {
            $info = $this->getCmd(null, $infoLogicalId);
            if (is_object($info)) $cmd->setValue($info->getId());
        }
        $cmd->save();
        return $cmd;
    }

    /**
     * Crée les commandes du workflow :
     *   Programme (select) → paramètres (select par paramètre) → Démarrer
     * Supprime les anciennes commandes "Sélectionner xxx" (une par programme).
     */
    private function createSelectionWorkflowCommands($dataDir = null) {
        // Nettoyage des anciennes commandes "select_<programme>"
        $removed = 0;
        foreach ($this->getCmd() as $old) {
            if (strpos($old->getLogicalId(), 'select_') === 0) { $old->remove(); $removed++; }
        }
        if ($removed > 0) log::add('hon', 'info', $removed . ' anciennes commandes "Sélectionner ..." supprimées pour ' . $this->getName());

        // Infos
        $this->createInfoCommand('selectedProgram',     'Programme sélectionné', 'string');
        $this->createInfoCommand('selectedProgramInfo', 'Infos du programme',    'string');

        // Select programme
        $programs = $this->getAvailablePrograms($dataDir);
        $pairs    = ['|Aucun'];
        foreach ($programs as $name => $label) $pairs[] = $name . '|' . $label;
        $this->upsertSelectCommand('program', 'Programme', implode(';', $pairs), 'selectedProgram');
        log::add('hon', 'info', 'Commande "Programme" : ' . count($programs) . ' programmes pour ' . $this->getName());

        // Un select (ou slider) + une info par paramètre modifiable
        foreach ($this->getManagedParams() as $param => $def) {
            $isSlider = (($def['widget'] ?? 'select') == 'slider');
            $info = $this->getCmd(null, 'desired_' . $param);
            if (!is_object($info)) {
                $this->createInfoCommand('desired_' . $param, $this->freeCmdName('Réglage ' . mb_strtolower($def['name'])), $isSlider ? 'numeric' : 'string', $isSlider ? $def['unit'] : '');
            } elseif ($isSlider && $info->getSubType() != 'numeric') {
                $info->setSubType('numeric');
                $info->setUnite($def['unit']);
                $info->save();
            }
            $this->upsertSelectCommand('set_' . $param, 'Choix ' . mb_strtolower($def['name']), '', 'desired_' . $param, null, $isSlider ? 'slider' : 'select');
        }

        // Boutons
        $this->createActionCommand('start_selected',  'Démarrer le programme sélectionné');
        $this->createActionCommand('clear_selection', 'Effacer la sélection');

        // Réapplique le programme déjà sélectionné (listes à jour après une synchro)
        $info     = $this->getCmd(null, 'selectedProgram');
        $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
        if ($selected !== '' && isset($programs[$selected])) $this->selectProgram($selected);
        else                                                 $this->clearSelection();
    }

    /** Sélection d'un programme : adapte chaque paramètre et pré-remplit les valeurs par défaut */
    public function selectProgram($programName) {
        $programName = trim((string)$programName);
        if ($programName === '' || in_array(strtolower($programName), ['none', 'aucun', 'effacer'])) {
            $this->clearSelection();
            return;
        }

        // Accepte aussi le libellé (ex. depuis un scénario : "Coton")
        $programs = $this->getAvailablePrograms();
        if (!isset($programs[$programName])) {
            $found = array_search($programName, $programs);
            if ($found === false) throw new Exception('Programme inconnu : ' . $programName);
            $programName = $found;
        }

        log::add('hon', 'info', 'Sélection programme ' . $programName . ' (' . $programs[$programName] . ') sur ' . $this->getName());
        $params = $this->getProgramParameters($programName) ?? [];

        $infoSel = $this->getCmd(null, 'selectedProgram');
        if (is_object($infoSel)) $infoSel->event($programName);

        foreach ($this->getManagedParams() as $param => $def) {
            $spec    = self::getParamSpec($params[$param] ?? null);
            $setCmd  = $this->getCmd(null, 'set_' . $param);
            $infoCmd = $this->getCmd(null, 'desired_' . $param);
            if (!is_object($setCmd) || !is_object($infoCmd)) continue;

            if (($def['widget'] ?? 'select') == 'slider') {
                // Slider : bornes et pas du programme (bloqué sur la valeur si fixe ou absent)
                $min  = ($spec === null) ? 0 : ($spec['fixed'] ? $spec['default'] : ($spec['min'] ?? min($spec['values'])));
                $max  = ($spec === null) ? 0 : ($spec['fixed'] ? $spec['default'] : ($spec['max'] ?? max($spec['values'])));
                $step = ($spec === null || $spec['fixed']) ? 1 : ($spec['step'] ?? 1);
                $setCmd->setConfiguration('minValue', strval($min));
                $setCmd->setConfiguration('maxValue', strval($max));
                $setCmd->setConfiguration('step',     strval($step));
                $infoCmd->event($spec === null ? '' : $spec['default']);
            } elseif ($spec === null) {
                $setCmd->setConfiguration('listValue', '');
                $infoCmd->event('');
            } else {
                $pairs = [];
                foreach ($spec['values'] as $v) {
                    $label   = $this->formatParamValue($param, $v) . ($spec['fixed'] ? ' (fixe)' : '');
                    $pairs[] = $v . '|' . $label;
                }
                $setCmd->setConfiguration('listValue', implode(';', $pairs));
                $infoCmd->event($spec['default']);
            }
            $setCmd->save();
        }

        $this->updateSelectionSummary();
        $this->refreshWidget();
    }

    /** Efface la sélection et vide les listes de paramètres */
    public function clearSelection() {
        foreach (['selectedProgram', 'selectedProgramInfo'] as $id) {
            $c = $this->getCmd(null, $id);
            if (is_object($c)) $c->event('');
        }
        foreach ($this->getManagedParams() as $param => $def) {
            $setCmd = $this->getCmd(null, 'set_' . $param);
            if (is_object($setCmd)) {
                $setCmd->setConfiguration('listValue', '');
                $setCmd->save();
            }
            $infoCmd = $this->getCmd(null, 'desired_' . $param);
            if (is_object($infoCmd)) $infoCmd->event('');
        }
        $this->refreshWidget();
        log::add('hon', 'info', 'Sélection effacée sur ' . $this->getName());
    }

    /** Modifie un paramètre du programme sélectionné (valeur ramenée sur une valeur autorisée) */
    public function setProgramParam($param, $value) {
        $managed = $this->getManagedParams();
        if (!isset($managed[$param])) throw new Exception('Paramètre non géré : ' . $param);

        $info     = $this->getCmd(null, 'selectedProgram');
        $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
        if ($selected === '') throw new Exception('Sélectionnez d\'abord un programme');

        $params = $this->getProgramParameters($selected) ?? [];
        $spec   = self::getParamSpec($params[$param] ?? null);
        if ($spec === null) throw new Exception($managed[$param]['name'] . ' non disponible pour ce programme');
        if ($spec['fixed']) {
            log::add('hon', 'info', $managed[$param]['name'] . ' est fixe pour ce programme → ' . $spec['default']);
            $value = $spec['default'];
        } elseif ($value === '' || $value === null) {
            $value = $spec['default'];
        } else {
            $value = self::normalizeToSpec(is_numeric($value) ? 0 + $value : $value, $spec);
        }

        $infoCmd = $this->getCmd(null, 'desired_' . $param);
        if (is_object($infoCmd)) $infoCmd->event($value);
        log::add('hon', 'info', $managed[$param]['name'] . ' = ' . $this->formatParamValue($param, $value) . ' (' . $selected . ')');
        $this->updateSelectionSummary();
        // Réaffiche la tuile : la mise à jour en direct de Jeedom n'affiche pas les retours à la ligne
        $this->refreshWidget();
    }

    /** Paramètres à envoyer au lancement (uniquement ceux modifiables par le programme) */
    public function getSelectedParameters($programName) {
        $params = $this->getProgramParameters($programName) ?? [];
        $out    = [];
        foreach ($this->getManagedParams() as $param => $def) {
            $spec = self::getParamSpec($params[$param] ?? null);
            if ($spec === null || $spec['fixed']) continue;
            $infoCmd = $this->getCmd(null, 'desired_' . $param);
            $raw     = is_object($infoCmd) ? trim((string)$infoCmd->execCmd()) : '';
            if ($raw === '') continue;
            $out[$param] = (string)self::normalizeToSpec(is_numeric($raw) ? 0 + $raw : $raw, $spec);
        }
        return $out;
    }

    /** Met à jour "Infos du programme" avec le programme et les valeurs choisies */
    public function updateSelectionSummary() {
        $info     = $this->getCmd(null, 'selectedProgram');
        $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
        $lines    = [];
        if ($selected !== '') {
            $lines[] = self::getProgramLabel($selected);
            foreach ($this->getManagedParams() as $param => $def) {
                $c   = $this->getCmd(null, 'desired_' . $param);
                $val = is_object($c) ? trim((string)$c->execCmd()) : '';
                if ($val !== '') $lines[] = $def['name'] . ' : ' . $this->formatParamValue($param, $val);
            }
        }
        $summary = $this->getCmd(null, 'selectedProgramInfo');
        if (is_object($summary)) $summary->event(implode("\n", $lines));
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

    /** Valeur transmise à une action (select, slider ou message) */
    private static function optionValue($_options) {
        foreach (['select', 'slider', 'message'] as $k) {
            if (isset($_options[$k]) && $_options[$k] !== '') return trim((string)$_options[$k]);
        }
        return '';
    }

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) throw new Exception('Équipement non trouvé');

        $logicalId = $this->getLogicalId();
        log::add('hon', 'debug', 'Exécution commande ' . $logicalId . ' sur ' . $eqLogic->getName() . ' ' . json_encode($_options));

        switch ($logicalId) {
            case 'refresh':
                $eqLogic->refreshInfo();
                return;

            case 'stop':
            case 'pause':
            case 'resume':
                $result = $eqLogic->launchProgram($logicalId);
                log::add('hon', 'info', ucfirst($logicalId) . ' sur ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
                if ($result) $eqLogic->scheduleRefresh();
                return;

            case 'program':
                $eqLogic->selectProgram(self::optionValue($_options));
                return;

            case 'clear_selection':
                $eqLogic->clearSelection();
                return;

            case 'start_selected':
                $info     = $eqLogic->getCmd(null, 'selectedProgram');
                $selected = is_object($info) ? trim((string)$info->execCmd()) : '';
                if ($selected === '') throw new Exception('Aucun programme sélectionné');

                $parameters = $eqLogic->getSelectedParameters($selected);
                if (isset($_options['parameters']) && is_array($_options['parameters'])) {
                    $parameters = array_merge($parameters, $_options['parameters']);
                }
                log::add('hon', 'info', 'Démarrage ' . $selected . ' sur ' . $eqLogic->getName() . ' avec ' . json_encode($parameters));

                $result = $eqLogic->launchProgram($selected, $parameters);
                if (!$result) throw new Exception('Échec du lancement du programme ' . $selected);
                $eqLogic->scheduleRefresh();
                return;
        }

        // set_<paramètre> : modification d'un paramètre du programme sélectionné
        if (strpos($logicalId, 'set_') === 0) {
            $eqLogic->setProgramParam(substr($logicalId, 4), self::optionValue($_options));
            return;
        }

        log::add('hon', 'warning', 'Commande non reconnue : ' . $logicalId);
    }
}

?>
