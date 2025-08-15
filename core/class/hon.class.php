

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
        
        // Rafraîchir les données des équipements toutes les minutes
        self::refreshAllDevices();
    }

    /**
     * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
     */
    public static function cron15() {
        // Contrôler la validité des tokens et refresh automatique à 5h30
        self::checkTokenValidityAndRefreshAt530();
    }

    /**
     * Fonction exécutée automatiquement toutes les heures par Jeedom
     */
  /*  public static function cronHourly() {
        self::syncDevices();
    } */

    /**
     * Rafraîchit les données de tous les équipements hon
     */
    public static function refreshAllDevices() {
        try {
            $eqLogics = eqLogic::byType('hon', true);
            
            if (!is_array($eqLogics) || empty($eqLogics)) {
                return;
            }
            
            // Vérifier les tokens
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'warning', 'Tokens non disponibles pour le rafraîchissement');
                return;
            }
            
            // Préparer les données pour le script Python
            $devices = [];
            foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getIsEnable()) {
                    $lastRefresh = (int)$eqLogic->getConfiguration('lastRefresh', 0);
                    $currentTime = time();
                    
                    // Vérifier si le dernier rafraîchissement date de plus d'45 sec
                    if (($currentTime - $lastRefresh) >= 45) {
                        // S'assurer que la MAC est au bon format (avec des tirets)
                        $macAddress = $eqLogic->getConfiguration('macAddress');
                        $macAddress = str_replace(':', '-', $macAddress); // Convertir : en -
                        
                        $devices[] = [
                            'mac_address' => $macAddress,
                            'appliance_type' => self::getApplianceTypeCode($eqLogic->getConfiguration('applianceType')),
                            'equipment_id' => $eqLogic->getId()
                        ];
                    }
                }
            }
            
            if (empty($devices)) {
                return; // Aucun appareil à rafraîchir
            }
            
            log::add('hon', 'debug', 'Rafraîchissement de ' . count($devices) . ' appareils');
            
            // Exécuter le script Python optimisé
            $result = self::executeQuickUpdate($devices, $tokens);
            
            if ($result && isset($result['devices'])) {
                foreach ($result['devices'] as $deviceResult) {
                    if ($deviceResult['success']) {
                       self::updateDeviceFromQuickDataWithTranslation($deviceResult);
                    } else {
                        log::add('hon', 'warning', 'Échec rafraîchissement ' . $deviceResult['mac_address'] . ': ' . ($deviceResult['error'] ?? 'Erreur inconnue'));
                    }
                }
                
                // Note: Suppression du refresh automatique du token Cognito ici
                // Le token sera seulement vérifié toutes les 15 minutes sans refresh automatique
                
            } else {
                log::add('hon', 'warning', 'Résultat invalide du script de mise à jour');
            }
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur dans refreshAllDevices : ' . $e->getMessage());
        }
    }

    /**
     * Contrôle la validité des tokens et refresh automatique à 5h30
     */
    public static function checkTokenValidityAndRefreshAt530() {
        try {
            $lastTokenTime = (int)config::byKey('lastTokenTime', 'hon', 0);
            $currentTime = time();
            $tokenAge = $currentTime - $lastTokenTime;
            
            // Vérifier l'âge des tokens
            if ($lastTokenTime === 0) {
                log::add('hon', 'warning', 'Aucun token généré - une synchronisation manuelle est requise');
                return;
            }
            
            // Refresh automatique à 5h30 (19800 secondes)
            if ($tokenAge >= 19800) {
                log::add('hon', 'info', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - refresh automatique à 5h30...');
                
                $refreshSuccess = self::refreshTokens();
                if ($refreshSuccess) {
                    log::add('hon', 'info', 'Refresh automatique des tokens réussi');
                } else {
                    log::add('hon', 'error', 'Échec du refresh automatique des tokens à 5h30');
                }
                return;
            }
            
            // Alertes selon l'âge des tokens (sans refresh)
            if ($tokenAge > 18000) { // Plus de 5 heures
                log::add('hon', 'info', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - refresh automatique dans ' . round((19800 - $tokenAge)/60) . ' minutes');
            } elseif ($tokenAge > 14400) { // Plus de 4 heures
                log::add('hon', 'debug', 'Tokens âgés de ' . round($tokenAge/3600, 1) . 'h - encore valides');
            } else {
                log::add('hon', 'debug', 'Tokens valides (âge: ' . round($tokenAge/3600, 1) . 'h)');
            }
            
            // Vérifier la présence des tokens en cache
            $cachedIdToken = config::byKey('cachedIdToken', 'hon');
            $cachedCognitoToken = config::byKey('cachedCognitoToken', 'hon');
            
            if (empty($cachedIdToken) || empty($cachedCognitoToken)) {
                log::add('hon', 'error', 'Tokens manquants en cache - synchronisation manuelle requise');
            }
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors du contrôle de validité des tokens : ' . $e->getMessage());
        }
    }

    /**
     * Contrôle uniquement la validité des tokens (sans refresh automatique) - DEPRECATED
     * Remplacé par checkTokenValidityAndRefreshAt530()
     */
    public static function checkTokenValidityOnly() {
        // Cette méthode est conservée pour compatibilité mais deprecated
        log::add('hon', 'debug', 'Méthode checkTokenValidityOnly() appelée - utilisez checkTokenValidityAndRefreshAt530()');
        self::checkTokenValidityAndRefreshAt530();
    }

    /**
     * Exécute le script Python optimisé pour la mise à jour rapide
     */
    private static function executeQuickUpdate($devices, $tokens) {
        try {
            $resourcesDir = __DIR__ . '/../../resources';
            
            // S'assurer que le script Python existe
            $scriptPath = self::ensureCronScript();
            
            if (!$scriptPath || !file_exists($scriptPath)) {
                log::add('hon', 'error', 'Impossible de créer le script de mise à jour');
                return false;
            }
            
            // Mode multi-appareils si plusieurs appareils
            if (count($devices) === 1) {
                // Mode simple
                $device = $devices[0];
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_cron_updater.py ' .
                       escapeshellarg($device['mac_address']) . ' ' .
                       escapeshellarg($device['appliance_type']) . ' ' .
                       escapeshellarg($tokens['id_token']) . ' ' .
                       escapeshellarg($tokens['cognito_token']);
            } else {
                // Mode multi-appareils
                $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_cron_updater.py ' .
                       escapeshellarg($devices[0]['mac_address']) . ' ' .
                       escapeshellarg($devices[0]['appliance_type']) . ' ' .
                       escapeshellarg($tokens['id_token']) . ' ' .
                       escapeshellarg($tokens['cognito_token']) . ' multi';
                
                // Ajouter les autres appareils
               foreach ($devices as $device) {
           $cmd .= ' ' . escapeshellarg($device['mac_address']) .
                    ' ' . escapeshellarg($device['appliance_type']);
                }
            }
            
            // Exécution avec timeout
            $output = shell_exec($cmd . ' 2>&1');
            
            if (empty($output)) {
                log::add('hon', 'warning', 'Aucune sortie du script de mise à jour');
                return false;
            }
            
            log::add('hon', 'debug', 'Sortie complète script : ' . $output);
            
            // Extraire seulement le JSON de la sortie (ignorer les warnings)
            $lines = explode("\n", trim($output));
            $jsonLine = null;
            
            // Chercher la ligne qui commence par { (le JSON)
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '{') === 0) {
                    $jsonLine = $line;
                    break;
                }
            }
            
            if (!$jsonLine) {
                log::add('hon', 'warning', 'Aucune sortie JSON trouvée dans : ' . $output);
                return false;
            }
            
            // Parser la sortie JSON
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

    /**
     * Convertit le type d'appareil en code API
     */
    private static function getApplianceTypeCode($applianceType) {
        $typeMapping = [
            'Machine à laver' => 'WM',
            'WM' => 'WM',
            'Sèche-linge' => 'TD', 
            'TD' => 'TD'
        ];
        
        return $typeMapping[$applianceType] ?? 'WM';
    }

    /**
     * Met à jour un équipement depuis les données du script rapide - VERSION MAPPING MANUEL CORRIGÉE
     */
    private static function updateDeviceFromQuickData($deviceResult) {
        try {
            $macAddress = $deviceResult['mac_address'];
            
            // Essayer de trouver l'équipement avec différents formats de MAC
            $eqLogic = self::byLogicalId($macAddress, 'hon');
            if (!is_object($eqLogic)) {
                // Essayer avec des : au lieu de -
                $macWithColons = str_replace('-', ':', $macAddress);
                $eqLogic = self::byLogicalId($macWithColons, 'hon');
            }
            
            if (!is_object($eqLogic)) {
                log::add('hon', 'warning', 'Équipement non trouvé pour MAC : ' . $macAddress);
                return;
            }
            
            $data = $deviceResult['data'] ?? [];
            $hasUpdates = false;
            
            // Mapping étendu des clés Python vers les IDs logiques des commandes
            $keyMapping = [
                // Mapping existant
                'machine_mode' => 'machMode',
                'program_code' => 'prCode',
                'remaining_time' => 'remainingTimeMM',
                'door_lock' => 'doorLockStatus',
                'door_status' => 'doorStatus',
                'errors' => 'errors',
                'temperature' => 'temp',
                'spin_speed' => 'spinSpeed',
                'total_cycles' => 'totalWashCycle',
                'current_cycle' => 'currentWashCycle',
                'remote_control' => 'remoteCtrValid',
                'total_water_used' => 'totalWaterUsed',
                'current_water_used' => 'currentWaterUsed',
                'total_electricity_used' => 'totalElectricityUsed',
                'current_electricity_used' => 'currentElectricityUsed',
                'estimated_weight' => 'actualWeight',
                'auto_detergent' => 'autoDetergentStatus',
                'auto_softener' => 'autoSoftenerStatus',
                'dry_level' => 'dryLevel',
                'sterilization_status' => 'sterilizationStatus',
                'status' => 'machine_state',
                'connection_status' => 'connection_status',
                'estimated_end_time' => 'estimated_end_time'
            ];
            
            foreach ($data as $pythonKey => $valueData) {
                if (isset($keyMapping[$pythonKey])) {
                    $cmdLogicalId = $keyMapping[$pythonKey];
                } else {
                    // Ignorer les données non mappées (ne plus créer de commandes automatiquement)
                    log::add('hon', 'debug', 'Donnée ignorée (non mappée): ' . $pythonKey . ' pour ' . $eqLogic->getName());
                    continue;
                }
                
                $cmd = $eqLogic->getCmd(null, $cmdLogicalId);
                
                if (is_object($cmd)) {
                    $newValue = $valueData['value'];
                    $currentValue = $cmd->execCmd();
                    
                    // Vérifier si la valeur a changé (conversion pour comparaison)
                    if (is_bool($newValue)) {
                        $newValue = $newValue ? 1 : 0;
                    }
                    
                    if ($currentValue != $newValue) {
                        $cmd->event($newValue);
                        $hasUpdates = true;
                        
                        // Log détaillé pour les changements importants
                        if (in_array($pythonKey, ['machine_mode', 'status', 'remaining_time', 'errors', 'program_code'])) {
                            log::add('hon', 'info', 
                                $eqLogic->getName() . ' - ' . $pythonKey . ' : ' . 
                                $currentValue . ' → ' . $newValue
                            );
                        } else {
                            log::add('hon', 'debug', 
                                $eqLogic->getName() . ' - ' . $pythonKey . ' : ' . 
                                $currentValue . ' → ' . $newValue
                            );
                        }
                    }
                } else {
                    // Commande non trouvée - mais on ne crée plus automatiquement
                    log::add('hon', 'debug', 'Commande non trouvée: ' . $cmdLogicalId . ' (clé Python: ' . $pythonKey . ') pour ' . $eqLogic->getName());
                }
            }
            
            // Mettre à jour le timestamp
            $eqLogic->setConfiguration('lastRefresh', time());
            $eqLogic->save();
            
            if ($hasUpdates) {
                log::add('hon', 'debug', 'Données mises à jour pour : ' . $eqLogic->getName());
            } else {
                log::add('hon', 'debug', 'Aucune mise à jour pour : ' . $eqLogic->getName() . ' (' . count($data) . ' valeurs reçues)');
            }
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur updateDeviceFromQuickData : ' . $e->getMessage());
        }
    }

    /**
     * Crée le script Python optimisé pour le cron s'il n'existe pas
     */
    private static function ensureCronScript() {
        $resourcesDir = __DIR__ . '/../../resources';
        $scriptPath = $resourcesDir . '/hon_cron_updater.py';
        
        if (file_exists($scriptPath)) {
            return $scriptPath; // Script déjà créé
        }
        
        // Créer le répertoire si nécessaire
        if (!is_dir($resourcesDir)) {
            mkdir($resourcesDir, 0755, true);
        }
        
        $pythonScript = '#!/usr/bin/env python3
"""Script de mise à jour hOn optimisé pour le cron Jeedom - Sans refresh automatique des tokens"""
import asyncio, aiohttp, json, sys, os
from datetime import datetime, timezone, timedelta
from enum import IntEnum

import logging
logging.basicConfig(level=logging.WARNING)
_LOGGER = logging.getLogger(__name__)

API_URL = "https://api-iot.he.services"
OS = "android"
APP_VERSION = "2.0.10"

class ApplianceType(IntEnum):
    WASHING_MACHINE = 1
    TUMBLE_DRYER = 2
    WASH_DRYER = 3

APPLIANCE_MAPPING = {"WM": ApplianceType.WASHING_MACHINE, "TD": ApplianceType.TUMBLE_DRYER, "WD": ApplianceType.WASH_DRYER}

class HonQuickConnection:
    def __init__(self, id_token, cognito_token):
        self._id_token = id_token
        self._cognito_token = cognito_token
        self._session = None
        
    @property
    def _headers(self):
        return {"Content-Type": "application/json", "cognito-token": self._cognito_token, "id-token": self._id_token, "User-Agent": "hOn-Jeedom/1.0"}
    
    async def _ensure_session(self):
        if self._session is None:
            timeout = aiohttp.ClientTimeout(total=10)
            self._session = aiohttp.ClientSession(timeout=timeout, connector=aiohttp.TCPConnector(ssl=False, limit=2))
    
    async def get_device_status(self, mac_address, appliance_type):
        await self._ensure_session()
        params = {"macAddress": mac_address, "applianceType": appliance_type, "category": "CYCLE"}
        url = f"{API_URL}/commands/v1/context"
        try:
            async with self._session.get(url, params=params, headers=self._headers) as response:
                if response.status == 200:
                    data = await response.json()
                    return data.get("payload", {})
                elif response.status == 401:
                    _LOGGER.warning("Token expiré - refresh manuel requis")
                    return {}
                else:
                    return {}
        except Exception as e:
            return {}
    
    async def close(self):
        if self._session:
            await self._session.close()

class HonDataExtractor:
    def __init__(self, appliance_type):
        self.appliance_type = appliance_type
        self.key_parameters = {
            "machMode": "machine_mode", "prCode": "program_code", "remainingTimeMM": "remaining_time",
            "doorLockStatus": "door_lock", "doorStatus": "door_status", "errors": "errors",
            "temp": "temperature", "spinSpeed": "spin_speed", "totalWashCycle": "total_cycles",
            "currentWashCycle": "current_cycle", "pause": "pause_status", "remoteCtrValid": "remote_control",
            "totalWaterUsed": "total_water_used", "currentWaterUsed": "current_water_used",
            "totalElectricityUsed": "total_electricity_used", "currentElectricityUsed": "current_electricity_used",
            "actualWeight": "estimated_weight", "autoDetergentStatus": "auto_detergent",
            "autoSoftenerStatus": "auto_softener", "dryLevel": "dry_level", "sterilizationStatus": "sterilization_status"
        }
    
    def extract_essential_data(self, context):
        if not context or "shadow" not in context or "parameters" not in context["shadow"]:
            return {}
        parameters = context["shadow"]["parameters"]
        extracted = {}
        for api_key, jeedom_key in self.key_parameters.items():
            if api_key in parameters:
                param_data = parameters[api_key]
                if isinstance(param_data, dict) and "parNewVal" in param_data:
                    value = param_data["parNewVal"]
                    last_update = param_data.get("lastUpdate")
                    converted_value = self._convert_value(api_key, value)
                    extracted[jeedom_key] = {"value": converted_value, "last_update": last_update, "api_key": api_key}
        extracted.update(self._calculate_derived_values(extracted, context))
        return extracted
    
    def _convert_value(self, key, value):
        try:
            if key in ["remainingTimeMM", "temp", "spinSpeed", "totalWashCycle", "currentWashCycle", "dryLevel", "actualWeight"]:
                return int(value) if value != "" else 0
            elif key in ["doorLockStatus", "doorStatus", "pause", "remoteCtrValid", "autoDetergentStatus", "autoSoftenerStatus", "sterilizationStatus"]:
                return value == "1"
            elif key in ["totalWaterUsed", "currentWaterUsed", "totalElectricityUsed", "currentElectricityUsed"]:
                return round(float(value) / 100.0, 2) if value != "" else 0.0
            else:
                return str(value)
        except (ValueError, TypeError):
            return value
    
    def _calculate_derived_values(self, extracted, context):
        derived = {}
        if "machine_mode" in extracted:
            mode = extracted["machine_mode"]["value"]
            status_map = {0: "Off", 1: "Ready", 2: "Running", 3: "Pause", 4: "Scheduled", 5: "Error", 6: "Finished", 7: "Finished"}
            derived["status"] = {"value": status_map.get(int(mode), "Unknown"), "last_update": datetime.now(timezone.utc).isoformat(), "api_key": "calculated"}
        if "remaining_time" in extracted and extracted["remaining_time"]["value"] > 0:
            end_time = datetime.now(timezone.utc) + timedelta(minutes=extracted["remaining_time"]["value"])
            derived["estimated_end_time"] = {"value": end_time.isoformat(), "last_update": datetime.now(timezone.utc).isoformat(), "api_key": "calculated"}
        last_conn = context.get("lastConnEvent", {})
        is_connected = last_conn.get("category") == "CONNECTED"
        derived["connection_status"] = {"value": "Connected" if is_connected else "Disconnected", "last_update": last_conn.get("instantTime", datetime.now(timezone.utc).isoformat()), "api_key": "calculated"}
        return derived

async def update_single_device(connection, device_config):
    mac_address = device_config["mac_address"]
    appliance_type = device_config["appliance_type"]
    try:
        context = await connection.get_device_status(mac_address, appliance_type)
        if not context:
            return {"mac_address": mac_address, "success": False, "error": "No data received"}
        extractor = HonDataExtractor(appliance_type)
        extracted_data = extractor.extract_essential_data(context)
        return {"mac_address": mac_address, "appliance_type": appliance_type, "success": True, "data": extracted_data, "last_update": datetime.now(timezone.utc).isoformat()}
    except Exception as e:
        return {"mac_address": mac_address, "success": False, "error": str(e)}

async def main():
    if len(sys.argv) < 5:
        print(json.dumps({"success": False, "error": "Arguments manquants"}))
        return
    mac_address, appliance_type, id_token, cognito_token = sys.argv[1:5]
    devices_config = []
    if len(sys.argv) > 5 and sys.argv[5] == "multi":
        for i in range(6, len(sys.argv), 2):
            if i + 1 < len(sys.argv):
                devices_config.append({"mac_address": sys.argv[i], "appliance_type": sys.argv[i + 1]})
    else:
        devices_config.append({"mac_address": mac_address, "appliance_type": appliance_type})
    
    connection = HonQuickConnection(id_token, cognito_token)
    results = []
    try:
        # Note: Suppression du refresh automatique des tokens
        tasks = [update_single_device(connection, config) for config in devices_config]
        device_results = await asyncio.wait_for(asyncio.gather(*tasks, return_exceptions=True), timeout=30)
        for result in device_results:
            results.append(result if not isinstance(result, Exception) else {"success": False, "error": str(result)})
        final_result = {
            "success": True, "devices": results, "total_devices": len(devices_config),
            "successful_updates": len([r for r in results if r.get("success", False)]),
            "execution_time": datetime.now(timezone.utc).isoformat()
        }
        print(json.dumps(final_result, ensure_ascii=False))
    except asyncio.TimeoutError:
        print(json.dumps({"success": False, "error": "Timeout", "partial_results": results}))
    except Exception as e:
        print(json.dumps({"success": False, "error": f"Erreur: {str(e)}", "partial_results": results}))
    finally:
        await connection.close()

if __name__ == "__main__":
    asyncio.run(main())
';
        
        // Écrire le script Python
        file_put_contents($scriptPath, $pythonScript);
        chmod($scriptPath, 0755);
        
        log::add('hon', 'info', 'Script Python de cron créé : ' . $scriptPath);
        
        return $scriptPath;
    }

    /**
     * Vérifie l'expiration des tokens et les régénère si nécessaire (MANUEL OU AUTO à 5h30)
     */
    public static function checkTokenExpiration() {
        $lastTokenTime = config::byKey('lastTokenTime', 'hon', 0);
        $currentTime = time();
        
        // Auto-refresh à 5h30 (19800 secondes) ou manual check
        if (($currentTime - $lastTokenTime) > 19800) {
            log::add('hon', 'info', 'Tokens expirés (> 5h30) - refresh automatique nécessaire');
            return self::refreshTokens();
        }
        
        return true;
    }

    /**
     * Force la régénération des tokens (MANUEL ou AUTO à 5h30)
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
            
            // Récupération des tokens (avec cache ou régénération automatique)
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                log::add('hon', 'error', 'Impossible de récupérer les tokens hOn');
                return false;
            }
            
            // 1. Générer les JSONs des programmes et informations des appareils
            log::add('hon', 'info', 'Génération des fichiers JSON...');
            
            $dataDir = self::generateDeviceJsons($email, $password);
            if (!$dataDir) {
                log::add('hon', 'error', 'Erreur lors de la génération des JSONs');
                return false;
            }
            
            // 2. Chercher et charger l'index des appareils
            $indexData = self::loadDeviceIndex($dataDir);
            if (!$indexData) {
                log::add('hon', 'error', 'Impossible de charger l\'index des appareils');
                return false;
            }
            
            log::add('hon', 'info', count($indexData['devices']) . ' appareils trouvés dans l\'index');
            
            // 3. Création/mise à jour des équipements
// 3. Création/mise à jour des équipements
 foreach ($indexData['devices'] as $deviceInfo) {
    try {
        log::add('hon', 'info', 'Traitement équipement: ' . $deviceInfo['device']);
        self::createOrUpdateDeviceFromJson($deviceInfo, $dataDir);
        log::add('hon', 'info', 'Équipement terminé: ' . $deviceInfo['device']);
    } catch (Exception $e) {
        log::add('hon', 'error', 'Erreur équipement ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
        // Continuer avec l'équipement suivant
    } catch (Error $e) {
        log::add('hon', 'error', 'Erreur fatale équipement ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
        // Continuer avec l'équipement suivant
    }
}  
          
          
                  
          
            
            log::add('hon', 'info', 'Synchronisation terminée avec succès');
            return true;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors de la synchronisation : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Génère les fichiers JSON des programmes et informations des appareils
     * @return string|false Le répertoire de données ou false en cas d'erreur
     */
    private static function generateDeviceJsons($email, $password) {
        try {
            $resourcesDir = __DIR__ . '/../../resources';
            
            // Le script Python crée le dossier hon/data depuis le répertoire resources
            $dataDir = $resourcesDir . '/hon/data';
            
            // 1. Générer les JSONs des programmes (MAC_programme.json)
            log::add('hon', 'info', 'Génération des JSONs des programmes...');
            $cmd1 = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_json_generator_programme.py ' . 
                   escapeshellarg($email) . ' ' . escapeshellarg($password) . ' 2>&1';
            
            $output1 = shell_exec($cmd1);
            log::add('hon', 'debug', 'Sortie génération programmes : ' . $output1);
            
            // 2. Générer les JSONs des informations des appareils (device_MAC.json)
            log::add('hon', 'info', 'Génération des JSONs des informations des appareils...');
            $cmd2 = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_json_generator_device.py ' . 
                   escapeshellarg($email) . ' ' . escapeshellarg($password) . ' 2>&1';
            
            $output2 = shell_exec($cmd2);
            log::add('hon', 'debug', 'Sortie génération infos : ' . $output2);
            
            // 3. Trouver le répertoire contenant les fichiers générés
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
                if (!empty($files)) {
                    $actualDataDir = $path;
                    log::add('hon', 'info', 'Fichiers JSON trouvés dans : ' . $path);
                    break;
                }
            }
            
            if (!$actualDataDir) {
                log::add('hon', 'error', 'Aucun fichier JSON généré dans les emplacements : ' . implode(', ', $possiblePaths));
                return false;
            }
            
            // Compter les fichiers générés
            $programFiles = glob($actualDataDir . '/*_*.json');
            $deviceFiles = glob($actualDataDir . '/device_*.json');
            $indexFiles = glob($actualDataDir . '/*index*.json');
            
            log::add('hon', 'info', 'JSONs générés : ' . count($programFiles) . ' programmes, ' . count($deviceFiles) . ' infos appareils, ' . count($indexFiles) . ' index');
            
            // Sauvegarder le chemin des données
            config::save('dataDir', $actualDataDir, 'hon');
            
            return $actualDataDir;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur génération JSONs : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Charge l'index des appareils depuis les fichiers JSON
     * @param string $dataDir Le répertoire des données
     * @return array|false Les données d'index ou false en cas d'erreur
     */
    private static function loadDeviceIndex($dataDir) {
        try {
            // Chercher les fichiers d'index disponibles
            $indexFiles = [
                $dataDir . '/hon_devices_complete_index.json',
                $dataDir . '/hon_devices_index.json'
            ];
            
            $indexFile = null;
            foreach ($indexFiles as $file) {
                if (file_exists($file)) {
                    $indexFile = $file;
                    break;
                }
            }
            
            if (!$indexFile) {
                log::add('hon', 'info', 'Aucun fichier d\'index trouvé, création manuelle...');
                return self::createManualIndex($dataDir);
            }
            
            // Charger et adapter l'index selon son format
            $indexContent = file_get_contents($indexFile);
            $rawIndexData = json_decode($indexContent, true);
            
            if (!$rawIndexData || !isset($rawIndexData['devices'])) {
                log::add('hon', 'error', 'Format d\'index JSON invalide dans : ' . $indexFile);
                return self::createManualIndex($dataDir);
            }
            
            log::add('hon', 'info', 'Index chargé depuis : ' . basename($indexFile));
            
            // Adapter le format selon le type d'index
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

    /**
     * Adapte l'index complet au format attendu
     */
    private static function adaptCompleteIndex($rawIndexData) {
        $indexData = [
            'generated_at' => $rawIndexData['extraction_info']['extracted_at'] ?? date('Y-m-d H:i:s'),
            'total_devices' => count($rawIndexData['devices']),
            'devices' => []
        ];
        
        foreach ($rawIndexData['devices'] as $deviceInfo) {
            // Extraire la MAC depuis le filename
            $filename = $deviceInfo['filename'];
            $mac = str_replace(['device_', '.json'], '', $filename);
            $mac = str_replace('-', ':', $mac);
            
            $indexData['devices'][] = [
                'file' => $deviceInfo['file'] ?? $filename,
                'filename' => $filename,
                'device' => $deviceInfo['device'],
                'mac' => $mac,
                'mac_filename' => str_replace(':', '-', $mac),
                'type' => $deviceInfo['type'],
                'programs' => $deviceInfo['programs'] ?? 0,
                'settings' => $deviceInfo['settings'] ?? 0
            ];
        }
        
        return $indexData;
    }

    /**
     * Adapte l'index standard au format attendu
     */
    private static function adaptStandardIndex($rawIndexData) {
        // L'index standard est déjà au bon format
        return $rawIndexData;
    }

    /**
     * Crée un index manuel à partir des fichiers device_*.json
     */
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
                
                // Extraire la MAC depuis le nom de fichier
                $mac = str_replace(['device_', '.json'], '', $filename);
                $mac = str_replace('-', ':', $mac);
                
                // Charger les données du device pour récupérer le nom
                $deviceData = json_decode(file_get_contents($deviceFile), true);
                
                if (!$deviceData) {
                    continue;
                }
                
                $deviceName = 'Appareil hOn';
                $deviceType = 'unknown';
                
                // Essayer de récupérer le nom et le type depuis différents emplacements
                if (isset($deviceData['device_info']['nickname'])) {
                    $deviceName = $deviceData['device_info']['nickname'];
                } elseif (isset($deviceData['appliance']['applianceModelName'])) {
                    $deviceName = $deviceData['appliance']['applianceModelName'];
                } elseif (isset($deviceData['nickname'])) {
                    $deviceName = $deviceData['nickname'];
                }
                
                if (isset($deviceData['device_info']['applianceTypeName'])) {
                    $deviceType = $deviceData['device_info']['applianceTypeName'];
                } elseif (isset($deviceData['appliance']['applianceTypeName'])) {
                    $deviceType = $deviceData['appliance']['applianceTypeName'];
                } elseif (isset($deviceData['applianceTypeName'])) {
                    $deviceType = $deviceData['applianceTypeName'];
                }
                
                $indexData['devices'][] = [
                    'file' => $filename,
                    'filename' => $filename,
                    'device' => $deviceName,
                    'mac' => $mac,
                    'mac_filename' => str_replace(':', '-', $mac),
                    'type' => $deviceType,
                    'programs' => 0,
                    'settings' => 0
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

    /**
     * Récupère les tokens (avec cache mais SANS régénération automatique)
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
            
            // Tokens expirés ou inexistants - NE PAS REGENERER AUTOMATIQUEMENT
            log::add('hon', 'warning', 'Tokens expirés ou manquants - refresh manuel requis');
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
        $cmd = 'cd ' . escapeshellarg(__DIR__ . '/../../resources') . ' && python3 hon_get_tokens.py ' . 
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

    /**
     * Crée ou met à jour un équipement depuis les données JSON
     */
  
/**
 * Crée ou met à jour un équipement depuis les données JSON - VERSION ROBUSTE
 */
private static function createOrUpdateDeviceFromJson($deviceInfo, $dataDir) {
    try {
        $macAddress = $deviceInfo['mac'] ?? '';
        
        if (empty($macAddress)) {
            log::add('hon', 'warning', 'Équipement sans adresse MAC ignoré');
            return;
        }
        
        log::add('hon', 'info', '=== DÉBUT TRAITEMENT ÉQUIPEMENT: ' . $deviceInfo['device'] . ' ===');
        
        // Rechercher un équipement existant
        $eqLogic = self::byLogicalId($macAddress, 'hon');
        
        if (!is_object($eqLogic)) {
            log::add('hon', 'info', 'Création du nouvel équipement : ' . $deviceInfo['device']);
            $eqLogic = new hon();
            $eqLogic->setLogicalId($macAddress);
            $eqLogic->setEqType_name('hon');
        } else {
            log::add('hon', 'info', 'Mise à jour de l\'équipement existant : ' . $deviceInfo['device']);
        }
        
        // Configuration de base
        $eqLogic->setName($deviceInfo['device']);
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        
        // Configuration spécifique hOn
        $eqLogic->setConfiguration('macAddress', $macAddress);
        $eqLogic->setConfiguration('mac_filename', $deviceInfo['mac_filename']);
        $eqLogic->setConfiguration('applianceType', $deviceInfo['type']);
        $eqLogic->setConfiguration('programsCount', $deviceInfo['programs']);
        $eqLogic->setConfiguration('settingsCount', $deviceInfo['settings']);
        $eqLogic->setConfiguration('lastSync', date('Y-m-d H:i:s'));
        
        $eqLogic->save();
        
        // Créer les commandes depuis les JSONs - AVEC GESTION D'ERREURS
// Créer les commandes depuis les JSONs - AVEC GESTION D'ERREURS
try {
    log::add('hon', 'info', 'Début création commandes pour : ' . $deviceInfo['device']);
    
    // Désactiver le timeout PHP pour éviter les plantages
    set_time_limit(0);
    
    $eqLogic->createCommandsFromJson($dataDir);
    log::add('hon', 'info', 'Commandes créées avec succès pour : ' . $deviceInfo['device']);
} catch (Exception $e) {
    log::add('hon', 'error', 'Erreur création commandes pour ' . $deviceInfo['device'] . ' : ' . $e->getMessage());
    // Continuer malgré l'erreur de création des commandes
}
      
        
        log::add('hon', 'info', '=== FIN TRAITEMENT ÉQUIPEMENT: ' . $deviceInfo['device'] . ' ===');
        
    } catch (Exception $e) {
        log::add('hon', 'error', 'Erreur création/MAJ équipement ' . ($deviceInfo['device'] ?? 'UNKNOWN') . ' : ' . $e->getMessage());
    }
}
  

    /*     * *********************Méthodes d'instance************************* */

    /**
     * Fonction exécutée automatiquement avant la sauvegarde de l'équipement
     */
    public function preSave() {
        
    }

    /**
     * Fonction exécutée automatiquement après la sauvegarde de l'équipement - VERSION SIMPLIFIÉE
     */
    public function postSave() {
        // Ne créer AUCUNE commande automatiquement
        // Les commandes seront créées uniquement depuis les JSONs
    }

    /**
     * Crée les commandes pour l'équipement depuis les fichiers JSON - VERSION SIMPLIFIÉE
     */
    public function createCommandsFromJson($dataDir = null) {
        $macAddress = $this->getConfiguration('macAddress');
        $macFilename = $this->getConfiguration('mac_filename');
        
        if (empty($macAddress) || empty($macFilename)) {
            log::add('hon', 'error', 'MAC address manquante pour créer les commandes de ' . $this->getName());
            return;
        }
        
        // Utiliser le dataDir passé en paramètre ou celui de la config
        if ($dataDir === null) {
            $dataDir = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
        }
        
        log::add('hon', 'debug', 'Création commandes pour ' . $this->getName() . ' dans ' . $dataDir);
        
        // 1. Créer UNIQUEMENT les commandes info mappées
        $this->createMappedInfoCommandsWithTranslation();
        
        // 2. Créer les commandes d'action depuis les programmes
        $this->createActionCommandsFromPrograms($dataDir, $macFilename);
        
        // 3. Créer UNIQUEMENT les commandes d'action essentielles
        $this->createEssentialActionCommands();
        
        log::add('hon', 'info', 'Commandes créées pour ' . $this->getName());
    }


/**
 * Crée uniquement les commandes info qui sont dans le mapping selon le type d'appareil
 */
  
private function createMappedInfoCommands() {
    // Récupérer le type d'appareil
    $applianceType = $this->getConfiguration('applianceType', '');
    $applianceTypeCode = self::getApplianceTypeCode($applianceType);
    
    // Commandes communes à tous les appareils
    $commonCommands = [
        'machMode' => ['name' => 'Mode machine', 'subtype' => 'numeric'],
        'prCode' => ['name' => 'Code programme', 'subtype' => 'string'],
        'remainingTimeMM' => ['name' => 'Temps restant', 'subtype' => 'numeric', 'unit' => 'min'],
        'doorLockStatus' => ['name' => 'Verrouillage porte', 'subtype' => 'binary'],
        'doorStatus' => ['name' => 'État porte', 'subtype' => 'binary'],
        'errors' => ['name' => 'Erreurs', 'subtype' => 'string'],
       // 'pause' => ['name' => 'État pause', 'subtype' => 'binary'],
        'remoteCtrValid' => ['name' => 'Contrôle distant', 'subtype' => 'binary'],
        'machine_state' => ['name' => 'État machine', 'subtype' => 'string'],
        'connection_status' => ['name' => 'Statut connexion', 'subtype' => 'binary'],
        'estimated_end_time' => ['name' => 'Heure fin estimée', 'subtype' => 'string']
    ];
    
    // Commandes spécifiques aux machines à laver (WM) et lave-linge séchant (WD)
    $washingMachineCommands = [
        'temp' => ['name' => 'Température', 'subtype' => 'numeric', 'unit' => '°C'],
        'totalElectricityUsed' => ['name' => 'Électricité totale', 'subtype' => 'numeric', 'unit' => 'kWh'],
        'currentElectricityUsed' => ['name' => 'Électricité actuelle', 'subtype' => 'numeric', 'unit' => 'kWh'],
        'spinSpeed' => ['name' => 'Vitesse essorage', 'subtype' => 'numeric', 'unit' => 'rpm'],
        'totalWashCycle' => ['name' => 'Total cycles', 'subtype' => 'numeric'],
        'currentWashCycle' => ['name' => 'Cycle actuel', 'subtype' => 'numeric'],
        'totalWaterUsed' => ['name' => 'Eau totale utilisée', 'subtype' => 'numeric', 'unit' => 'L'],
        'currentWaterUsed' => ['name' => 'Eau actuelle', 'subtype' => 'numeric', 'unit' => 'L'],
        'actualWeight' => ['name' => 'Poids estimé', 'subtype' => 'numeric', 'unit' => 'kg'],
        'autoDetergentStatus' => ['name' => 'Auto lessive', 'subtype' => 'binary'],
        'autoSoftenerStatus' => ['name' => 'Auto adoucissant', 'subtype' => 'binary']
  
    ];
    
    // Commandes spécifiques aux sèche-linge (TD)
    $tumbleDryerCommands = [
      //  'temp' => ['name' => 'Température', 'subtype' => 'numeric', 'unit' => '°C'],
      //  'totalWashCycle' => ['name' => 'Total cycles', 'subtype' => 'numeric'],
      //  'currentWashCycle' => ['name' => 'Cycle actuel', 'subtype' => 'numeric'],
       // 'actualWeight' => ['name' => 'Poids estimé', 'subtype' => 'numeric', 'unit' => 'kg'],
        'dryLevel' => ['name' => 'Niveau séchage', 'subtype' => 'numeric'],
        'sterilizationStatus' => ['name' => 'Stérilisation', 'subtype' => 'binary']
    ];
    
    // Commandes spécifiques aux lave-linge séchant (WD) - combinaison des deux
  //  $washDryerCommands = array_merge($washingMachineCommands, [
    //    'dryLevel' => ['name' => 'Niveau séchage', 'subtype' => 'numeric', 'unit' => '%']
 //   ]);
    
    // Sélectionner les commandes selon le type d'appareil
    $specificCommands = [];
    switch ($applianceTypeCode) {
        case 'WM':
            $specificCommands = $washingMachineCommands;
            log::add('hon', 'info', 'Création des commandes pour machine à laver : ' . $this->getName());
            break;
            
        case 'TD':
            $specificCommands = $tumbleDryerCommands;
            log::add('hon', 'info', 'Création des commandes pour sèche-linge : ' . $this->getName());
            break;
            
        case 'WD':
            $specificCommands = $washDryerCommands;
            log::add('hon', 'info', 'Création des commandes pour lave-linge séchant : ' . $this->getName());
            break;
            
        default:
            // Type inconnu - créer toutes les commandes par sécurité
            $specificCommands = array_merge($washingMachineCommands, $tumbleDryerCommands);
            log::add('hon', 'warning', 'Type d\'appareil inconnu (' . $applianceType . '), création de toutes les commandes : ' . $this->getName());
            break;
    }
    
    // Fusionner les commandes communes et spécifiques
    $infoCommands = array_merge($commonCommands, $specificCommands);
    
    // Créer les commandes
    foreach ($infoCommands as $logicalId => $config) {
        $this->createInfoCommand(
            $logicalId, 
            $config['name'], 
            $config['subtype'], 
            $config['unit'] ?? ''
        );
    }
    
    log::add('hon', 'info', count($infoCommands) . ' commandes info créées pour ' . $this->getName() . ' (Type: ' . $applianceTypeCode . ')');
}



    /**
     * Crée les commandes d'action depuis les programmes disponibles
     */
    private function createActionCommandsFromPrograms($dataDir, $macFilename) {
        try {
            // Chercher tous les fichiers de programmes pour cet appareil
            $patterns = [
                $dataDir . '/' . $macFilename . '_*.json',                    // Format standard
                $dataDir . '/*' . str_replace('-', '_', $macFilename) . '*.json', // Underscores
                $dataDir . '/*' . str_replace('-', '', $macFilename) . '*.json'   // Sans séparateurs
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
                log::add('hon', 'debug', 'Patterns testés: ' . implode(', ', $patterns));
                
                // Lister tous les fichiers du répertoire pour debug
                $allFiles = glob($dataDir . '/*.json');
                log::add('hon', 'debug', 'Fichiers disponibles: ' . implode(', ', array_map('basename', $allFiles)));
                return;
            }
            
            log::add('hon', 'info', count($programFiles) . ' fichiers de programmes trouvés pour ' . $macFilename);
            
            // Créer une commande d'action pour chaque programme
            $programsCreated = 0;
            foreach ($programFiles as $programFile) {
                $content = file_get_contents($programFile);
                $programData = json_decode($content, true);
                
                if (!$programData) {
                    log::add('hon', 'warning', 'JSON invalide dans: ' . basename($programFile));
                    continue;
                }
                
                // Extraire les informations du programme selon différentes structures
                $programName = '';
                $displayName = '';
                
                // Structure 1: program_info
                if (isset($programData['program_info'])) {
                    $programInfo = $programData['program_info'];
                    $programName = $programInfo['name'] ?? '';
                    $displayName = $programInfo['display_name'] ?? $programInfo['displayName'] ?? $programName;
                }
                // Structure 2: program direct
                elseif (isset($programData['program'])) {
                    $programInfo = $programData['program'];
                    $programName = $programInfo['name'] ?? '';
                    $displayName = $programInfo['display_name'] ?? $programInfo['displayName'] ?? $programName;
                }
                // Structure 3: nom direct
                elseif (isset($programData['name'])) {
                    $programName = $programData['name'];
                    $displayName = $programData['display_name'] ?? $programData['displayName'] ?? $programName;
                }
                // Structure 4: utiliser le nom du fichier
                else {
                    $fileName = basename($programFile, '.json');
                    // Retirer la MAC du nom du fichier
                    $parts = explode('_', $fileName);
                    if (count($parts) > 1) {
                        array_shift($parts); // Retirer la première partie (MAC)
                        $programName = implode('_', $parts);
                        $displayName = str_replace('_', ' ', $programName);
                        $displayName = ucwords($displayName);
                    } else {
                        $programName = $fileName;
                        $displayName = $programName;
                    }
                }
                
                if (empty($programName)) {
                    log::add('hon', 'warning', 'Nom de programme vide dans: ' . basename($programFile));
                    continue;
                }
                
                // Nettoyer le nom du programme pour l'ID logique
                $cleanProgramName = preg_replace('/[^a-zA-Z0-9_]/', '_', $programName);
                $cleanProgramName = trim($cleanProgramName, '_');
                
                if (empty($cleanProgramName)) {
                    log::add('hon', 'warning', 'Nom de programme invalide après nettoyage: ' . $programName);
                    continue;
                }
                
                // Vérifier si la commande existe déjà
                $logicalId = 'start_' . $cleanProgramName;
                $existingCmd = $this->getCmd(null, $logicalId);
                
                if (is_object($existingCmd)) {
                    log::add('hon', 'debug', 'Programme déjà existant: ' . $displayName);
                    continue;
                }
                
                // Créer la commande d'action pour lancer ce programme
                $cmd = new honCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName('Lancer ' . $displayName);
                $cmd->setType('action');
                $cmd->setSubType('other');
                $cmd->setConfiguration('program', $programName);
                $cmd->setIsVisible(1);
                $cmd->save();
                
                $programsCreated++;
                log::add('hon', 'info', 'Programme créé: ' . $displayName . ' (' . $programName . ')');
            }
            
            log::add('hon', 'info', $programsCreated . ' programmes créés pour ' . $this->getName());
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur création commandes programmes : ' . $e->getMessage());
        }
    }

    /**
     * Crée une commande d'information
     */
    private function createInfoCommand($logicalId, $name, $subtype, $unit = '') {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            return; // Commande déjà créée
        }
        
        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('info');
        $cmd->setSubType($subtype);
        
        if (!empty($unit)) {
            $cmd->setUnite($unit);
        }
        
        // Configuration par défaut
        $cmd->setIsVisible(1);
        $cmd->setIsHistorized(0);
        
        // Historiser certaines commandes importantes
        if (in_array($logicalId, ['machine_state', 'remainingTimeMM', 'temp'])) {
            $cmd->setIsHistorized(1);
        }
        
        $cmd->save();
    }

   


    /**
     * Crée une commande d'action
     */
    private function createActionCommand($logicalId, $name, $program = '') {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            return; // Commande déjà créée
        }
        
        $cmd = new honCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($name);
        $cmd->setType('action');
        $cmd->setSubType('other');
        
        if (!empty($program)) {
            $cmd->setConfiguration('program', $program);
        }
        
        $cmd->setIsVisible(1);
        $cmd->save();
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
        $replace['#applianceType#'] = $this->getConfiguration('applianceType', 'N/A');
        $replace['#programsCount#'] = $this->getConfiguration('programsCount', '0');
        $replace['#lastSync#'] = $this->getConfiguration('lastSync', 'Jamais');
        
        // Icône selon le type
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

    /**
     * Rafraîchit les informations de l'équipement
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
            
            if (($currentTime - $lastRefresh) < 55) { // Minimum 1 minute entre les rafraîchissements
                return;
            }
            
            // Récupération du statut depuis les fichiers JSON ou API
            $statusData = $this->getDeviceStatus($macAddress);
            
            if ($statusData && is_array($statusData)) {
                $hasUpdates = false;
                
                // Mise à jour des commandes info
                foreach ($statusData as $key => $value) {
                    $cmd = $this->getCmd(null, $key);
                    if (is_object($cmd)) {
                        // Convertir les valeurs booléennes
                        if (is_bool($value)) {
                            $value = $value ? 1 : 0;
                        }
                        
                        // Vérifier si la valeur a changé
                        $currentValue = $cmd->execCmd();
                        if ($currentValue != $value) {
                            $cmd->event($value);
                            $hasUpdates = true;
                        }
                    }
                }
                
                // Mettre à jour le timestamp
                $this->setConfiguration('lastRefresh', $currentTime);
                $this->save();
                
                if ($hasUpdates) {
                    log::add('hon', 'info', 'Données mises à jour pour : ' . $this->getName());
                }
            }
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lors du rafraîchissement de ' . $this->getName() . ' : ' . $e->getMessage());
        }
    }

    /**
     * Récupère le statut d'un appareil
     */
    private function getDeviceStatus($macAddress) {
        try {
            // Chercher dans le répertoire de données configuré
            $dataDir = config::byKey('dataDir', 'hon', __DIR__ . '/../../resources/hon/data');
            $macFilename = str_replace(':', '-', $macAddress);
            $deviceFile = $dataDir . '/device_' . $macFilename . '.json';
            
            if (file_exists($deviceFile)) {
                $content = file_get_contents($deviceFile);
                $deviceData = json_decode($content, true);
                
                if ($deviceData) {
                    // Extraire les données de statut selon la structure du fichier
                    $statusData = [];
                    
                    // Structure complète avec current_state.context.shadow.parameters
                    if (isset($deviceData['current_state']['context']['shadow']['parameters'])) {
                        $parameters = $deviceData['current_state']['context']['shadow']['parameters'];
                        
                        foreach ($parameters as $key => $paramData) {
                            if (is_array($paramData) && isset($paramData['parNewVal'])) {
                                $value = $paramData['parNewVal'];
                                
                                // Convertir les valeurs numériques
                                if (is_numeric($value)) {
                                    $value = is_float($value + 0) ? (float)$value : (int)$value;
                                }
                                
                                $statusData[$key] = $value;
                            }
                        }
                    }
                    // Structure simplifiée avec current_state directement
                    elseif (isset($deviceData['current_state'])) {
                        $statusData = $deviceData['current_state'];
                    }
                    
                    // Ajouter des données d'identification si disponibles
                    if (isset($deviceData['device_identification']['extracted_info'])) {
                        $deviceInfo = $deviceData['device_identification']['extracted_info'];
                        foreach ($deviceInfo as $key => $value) {
                            if (is_scalar($value)) {
                                $statusData['device_' . $key] = $value;
                            }
                        }
                    }
                    
                    // Ajouter des données de connectivité
                    if (isset($deviceData['connectivity'])) {
                        $connectivity = $deviceData['connectivity'];
                        foreach ($connectivity as $key => $value) {
                            if (is_scalar($value)) {
                                $statusData['conn_' . $key] = $value;
                            }
                        }
                    }
                    
                    // Ajouter des statistiques
                    if (isset($deviceData['current_state']['statistics'])) {
                        $statistics = $deviceData['current_state']['statistics'];
                        
                        if (isset($statistics['programsCounter'])) {
                            $statusData['programs_counter'] = $statistics['programsCounter'];
                        }
                        
                        if (isset($statistics['temperatureUsage'])) {
                            foreach ($statistics['temperatureUsage'] as $tempKey => $tempValue) {
                                $statusData['stat_' . $tempKey] = $tempValue;
                            }
                        }
                    }
                    
                    return $statusData;
                }
            }
            
            // Si pas de fichier local, essayer d'utiliser l'API en temps réel
            return $this->getDeviceStatusFromAPI($macAddress);
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getDeviceStatus : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère le statut depuis l'API en temps réel
     */
    private function getDeviceStatusFromAPI($macAddress) {
        try {
            $tokens = self::getCachedTokens();
            if (!$tokens) {
                return false;
            }
            
            // Utiliser un script Python pour récupérer le statut en temps réel
            $resourcesDir = __DIR__ . '/../../resources';
            $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_get_status.py ' . 
                   escapeshellarg(config::byKey('email', 'hon')) . ' ' . 
                   escapeshellarg(config::byKey('password', 'hon')) . ' ' . 
                   escapeshellarg($macAddress);
            
            $output = shell_exec($cmd . ' 2>&1');
            
            if ($output) {
                $statusData = json_decode($output, true);
                if (is_array($statusData)) {
                    return $statusData;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur getDeviceStatusFromAPI : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lance un programme sur l'équipement
     */
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
            
            // Construire la commande avec le lanceur
            $resourcesDir = __DIR__ . '/../../resources';
            $cmd = 'cd ' . escapeshellarg($resourcesDir) . ' && python3 hon_launcher.py ' . 
                   escapeshellarg(config::byKey('email', 'hon')) . ' ' . 
                   escapeshellarg(config::byKey('password', 'hon')) . ' ' . 
                   escapeshellarg($programName) . ' ' . 
                   escapeshellarg($macAddress);
            
            // Ajouter les paramètres si fournis
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
            
            // Vérifier si le lancement a réussi
     $success = (strpos($output, 'SUCCÈS') !== false || 
           strpos($output, 'SUCCESS') !== false ||
           strpos($output, 'Programme lancé') !== false ||
           strpos($output, 'PROGRAMME MIS EN PAUSE') !== false ||
           strpos($output, 'Commande envoyée avec succès') !== false);
            
            // Programmer un rafraîchissement dans 5 secondes
            if ($success) {
                $this->scheduleRefresh(5);
            }
            
            return $success;
            
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur lancement programme : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Programme un rafraîchissement différé
     */
    public function scheduleRefresh($delaySeconds = 5) {
        // Utiliser le système de cron de Jeedom pour programmer un rafraîchissement
        $cmd = "sleep $delaySeconds && php " . __DIR__ . "/../../../../core/php/jeedom.php " .
               "eqLogic::byId(" . $this->getId() . ")->refreshInfo()";
        
        exec($cmd . " > /dev/null 2>&1 &");
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

    /**
     * Méthode pour forcer la synchronisation manuelle
     */
    public static function forceSyncDevices() {
        log::add('hon', 'info', 'Synchronisation manuelle déclenchée');
        return self::syncDevices();
    }

    /**
     * Retourne les statistiques du plugin
     */
    public static function getPluginStats() {
        $equipments = eqLogic::byType('hon');
        $totalCommands = 0;
        $enabledEquipments = 0;
        
        foreach ($equipments as $equipment) {
            if ($equipment->getIsEnable()) {
                $enabledEquipments++;
            }
            $totalCommands += count($equipment->getCmd());
        }
        
        return [
            'total_equipments' => count($equipments),
            'enabled_equipments' => $enabledEquipments,
            'total_commands' => $totalCommands,
            'data_dir' => config::byKey('dataDir', 'hon', 'Non configuré'),
            'last_sync' => config::byKey('lastSyncTime', 'hon', 'Jamais')
        ];
    }



private static $translationCache = [];

    private static function loadTranslations($applianceType) {
        if (isset(self::$translationCache[$applianceType])) {
            return self::$translationCache[$applianceType];
        }
        $resourcesDir = __DIR__ . '/../../resources';
        $translationFile = $resourcesDir . '/' . $applianceType . '_programme.json';
        if (!file_exists($translationFile)) {
            log::add('hon', 'warning', 'Fichier de traduction non trouvé : ' . $translationFile);
            return null;
        }
        $content = file_get_contents($translationFile);
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
    if (!$translations || !isset($translations['programs'])) {
        return "";  // ← VIDE SI PAS DE FICHIER
    }
    foreach ($translations['programs'] as $program) {
        if (isset($program['code']) && $program['code'] == $programCode) {
            return $program['display_name'] ?? $program['name'] ?? "";
        }
        if (isset($program['prCode']) && $program['prCode'] == $programCode) {
            return $program['display_name'] ?? $program['name'] ?? "";
        }
    }
    return "";  // ← VIDE SI PAS TROUVÉ
}

    public static function translateDryLevel($dryLevel, $applianceType = 'TD') {
        $translations = self::loadTranslations($applianceType);
        if (!$translations || !isset($translations['dry_levels'])) {
            return "Niveau " . $dryLevel;
        }
        foreach ($translations['dry_levels'] as $level) {
            if (isset($level['code']) && $level['code'] == $dryLevel) {
                return $level['display_name'] ?? $level['name'] ?? "Niveau " . $dryLevel;
            }
            if (isset($level['value']) && $level['value'] == $dryLevel) {
                return $level['display_name'] ?? $level['name'] ?? "Niveau " . $dryLevel;
            }
        }
        return "Niveau " . $dryLevel;
    }

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
            $data = $deviceResult['data'] ?? [];
            $hasUpdates = false;
            $applianceType = $eqLogic->getConfiguration('applianceType', 'WM');
            $applianceTypeCode = self::getApplianceTypeCode($applianceType);
            $keyMapping = [
                'machine_mode' => 'machMode', 'program_code' => 'prCode', 'remaining_time' => 'remainingTimeMM',
                'door_lock' => 'doorLockStatus', 'door_status' => 'doorStatus', 'errors' => 'errors',
                'temperature' => 'temp', 'spin_speed' => 'spinSpeed', 'total_cycles' => 'totalWashCycle',
                'current_cycle' => 'currentWashCycle', 'remote_control' => 'remoteCtrValid',
                'total_water_used' => 'totalWaterUsed', 'current_water_used' => 'currentWaterUsed',
                'total_electricity_used' => 'totalElectricityUsed', 'current_electricity_used' => 'currentElectricityUsed',
                'estimated_weight' => 'actualWeight', 'auto_detergent' => 'autoDetergentStatus',
                'auto_softener' => 'autoSoftenerStatus', 'dry_level' => 'dryLevel',
                'sterilization_status' => 'sterilizationStatus', 'status' => 'machine_state',
                'connection_status' => 'connection_status', 'estimated_end_time' => 'estimated_end_time'
            ];
            foreach ($data as $pythonKey => $valueData) {
                if (isset($keyMapping[$pythonKey])) {
                    $cmdLogicalId = $keyMapping[$pythonKey];
                } else {
                    continue;
                }
                $cmd = $eqLogic->getCmd(null, $cmdLogicalId);
                if (is_object($cmd)) {
                    $newValue = $valueData['value'];
                    if ($pythonKey === 'program_code') {
                        $translatedValue = self::translateProgramCode($newValue, $applianceTypeCode);
                        $translatedCmd = $eqLogic->getCmd(null, 'prCodeTranslated');
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
                        $translatedCmd = $eqLogic->getCmd(null, 'dryLevelTranslated');
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
                    if (is_bool($newValue)) {
                        $newValue = $newValue ? 1 : 0;
                    }
                    if ($currentValue != $newValue) {
                        $cmd->event($newValue);
                        $hasUpdates = true;
                    }
                }
            }
            $eqLogic->setConfiguration('lastRefresh', time());
            $eqLogic->save();
        } catch (Exception $e) {
            log::add('hon', 'error', 'Erreur updateDeviceFromQuickDataWithTranslation : ' . $e->getMessage());
        }
    }

    private function createMappedInfoCommandsWithTranslation() {
        $applianceType = $this->getConfiguration('applianceType', '');
        $applianceTypeCode = self::getApplianceTypeCode($applianceType);
        $this->createMappedInfoCommands();
        $this->createInfoCommand('prCodeTranslated', 'Nom du programme', 'string');
        if (in_array($applianceTypeCode, ['TD', 'WD'])) {
            $this->createInfoCommand('dryLevelTranslated', 'Niveau de séchage', 'string');
        }
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
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new Exception('Équipement non trouvé');
        }
        
        $logicalId = $this->getLogicalId();
        log::add('hon', 'debug', 'Exécution commande ' . $logicalId . ' sur ' . $eqLogic->getName());
        
        if ($logicalId == 'refresh') {
            // Commande de rafraîchissement
            $eqLogic->refreshInfo();
            log::add('hon', 'info', 'Rafraîchissement manuel de ' . $eqLogic->getName());
            
        } elseif ($logicalId == 'stop') {
            // Commande d'arrêt
            $result = $eqLogic->launchProgram('stop');
            log::add('hon', 'info', 'Arrêt de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
            
        } elseif ($logicalId == 'pause') {
            // Commande de pause
            $result = $eqLogic->launchProgram('pause');
            log::add('hon', 'info', 'Pause de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
            
        } elseif ($logicalId == 'resume') {
            // Commande de reprise
            $result = $eqLogic->launchProgram('resume');
            log::add('hon', 'info', 'Reprise de ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
            
        } elseif (strpos($logicalId, 'start_') === 0) {
            // Commande de lancement de programme
            $programName = $this->getConfiguration('program', '');
            if (!empty($programName)) {
                // Récupérer les paramètres depuis les options si fournis
                $parameters = [];
                if (isset($_options['parameters']) && is_array($_options['parameters'])) {
                    $parameters = $_options['parameters'];
                }
                
                $result = $eqLogic->launchProgram($programName, $parameters);
                log::add('hon', 'info', 'Lancement programme ' . $programName . ' sur ' . $eqLogic->getName() . ' : ' . ($result ? 'OK' : 'ÉCHEC'));
                
                if (!$result) {
                    throw new Exception('Échec du lancement du programme ' . $programName);
                }
            } else {
                throw new Exception('Programme non configuré pour la commande ' . $logicalId);
            }
        } else {
            log::add('hon', 'warning', 'Commande non reconnue : ' . $logicalId);
        }
        
        // Déclencher un rafraîchissement après certaines commandes
        if (in_array($logicalId, ['stop', 'pause', 'resume']) || strpos($logicalId, 'start_') === 0) {
            // Rafraîchissement différé pour laisser le temps à l'appareil de réagir
            $eqLogic->scheduleRefresh(3);
        }
    }
}

?>
