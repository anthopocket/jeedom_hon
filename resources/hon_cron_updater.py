#!/usr/bin/env python3
"""
Script de mise à jour des données hOn pour le cron Jeedom
Optimisé pour s'exécuter toutes les minutes
"""

import asyncio
import aiohttp
import logging
import json
import sys
import os
from datetime import datetime, timezone, timedelta
from enum import IntEnum

# Configuration du logger pour éviter les logs excessifs
logging.basicConfig(level=logging.WARNING)
_LOGGER = logging.getLogger(__name__)

# Constantes API
API_URL = "https://api-iot.he.services"
OS = "android"
APP_VERSION = "2.0.10"

class ApplianceType(IntEnum):
    WASHING_MACHINE = 1
    TUMBLE_DRYER = 2
    WASH_DRYER = 3

APPLIANCE_MAPPING = {
    "WM": ApplianceType.WASHING_MACHINE,
    "TD": ApplianceType.TUMBLE_DRYER,
    "WD": ApplianceType.WASH_DRYER
}

class HonTokenManager:
    """Gestionnaire de tokens avec cache et gestion d'expiration"""
    
    def __init__(self, jeedom_config_dir="/var/www/html/plugins/hon/data"):
        self.config_dir = jeedom_config_dir
        self.token_file = os.path.join(self.config_dir, "hon_tokens.json")
        
    def load_tokens(self):
        """Charge les tokens depuis le cache Jeedom ou le fichier"""
        try:
            # Essayer de charger depuis le fichier de tokens
            if os.path.exists(self.token_file):
                with open(self.token_file, 'r') as f:
                    tokens = json.load(f)
                    return tokens
            else:
                # Fallback: essayer de récupérer depuis la configuration Jeedom
                return self._load_from_jeedom_config()
        except Exception as e:
            _LOGGER.error(f"Erreur chargement tokens: {e}")
            return None
    
    def _load_from_jeedom_config(self):
        """Charge les tokens depuis la configuration Jeedom (fichier de config PHP)"""
        try:
            # Cette méthode sera appelée par le wrapper PHP qui passera les tokens
            return None
        except:
            return None
    
    def save_tokens(self, tokens):
        """Sauvegarde les tokens mis à jour"""
        try:
            os.makedirs(self.config_dir, exist_ok=True)
            tokens['last_update'] = datetime.now(timezone.utc).isoformat()
            
            with open(self.token_file, 'w') as f:
                json.dump(tokens, f, indent=2)
            return True
        except Exception as e:
            _LOGGER.error(f"Erreur sauvegarde tokens: {e}")
            return False
    
    def are_tokens_valid(self, tokens):
        """Vérifie si les tokens sont encore valides (moins de 5h)"""
        if not tokens or 'last_update' not in tokens:
            return False
        
        try:
            last_update = datetime.fromisoformat(tokens['last_update'].replace('Z', '+00:00'))
            age = datetime.now(timezone.utc) - last_update
            return age.total_seconds() < 18000  # 5 heures
        except:
            return False

class HonQuickConnection:
    """Connexion hOn optimisée pour les appels fréquents"""
    
    def __init__(self, id_token, cognito_token):
        self._id_token = id_token
        self._cognito_token = cognito_token
        self._session = None
        self._last_token_refresh = None
        
    @property
    def _headers(self):
        return {
            "Content-Type": "application/json",
            "cognito-token": self._cognito_token,
            "id-token": self._id_token,
            "User-Agent": "hOn-Jeedom/1.0"
        }
    
    async def _ensure_session(self):
        """S'assure qu'une session est disponible"""
        if self._session is None:
            timeout = aiohttp.ClientTimeout(total=10)  # Timeout court pour le cron
            self._session = aiohttp.ClientSession(
                timeout=timeout,
                connector=aiohttp.TCPConnector(ssl=False, limit=2)
            )
    
    async def refresh_cognito_token_if_needed(self):
        """Rafraîchit le token seulement si nécessaire"""
        now = datetime.now(timezone.utc)
        
        # Rafraîchir seulement si plus de 4 heures depuis le dernier refresh
        if (self._last_token_refresh is None or 
            (now - self._last_token_refresh).total_seconds() > 14400):
            
            await self._ensure_session()
            
            data = {
                "appVersion": APP_VERSION,
                "mobileId": "jeedom_hon_plugin",
                "os": OS,
                "osVersion": "31",
                "deviceModel": "jeedom_server"
            }
            
            headers = {"id-token": self._id_token}
            url = f"{API_URL}/auth/v1/login"
            
            try:
                async with self._session.post(url, headers=headers, json=data) as resp:
                    if resp.status == 200:
                        json_data = await resp.json()
                        new_token = json_data.get("cognitoUser", {}).get("Token")
                        if new_token:
                            self._cognito_token = new_token
                            self._last_token_refresh = now
                            return new_token
            except asyncio.TimeoutError:
                _LOGGER.warning("Timeout lors du refresh token - on continue avec l'ancien")
            except Exception as e:
                _LOGGER.warning(f"Erreur refresh token: {e}")
        
        return self._cognito_token
    
    async def get_device_status(self, mac_address, appliance_type):
        """Récupère le statut d'un appareil avec gestion d'erreurs"""
        await self._ensure_session()
        
        params = {
            "macAddress": mac_address,
            "applianceType": appliance_type,
            "category": "CYCLE"
        }
        
        url = f"{API_URL}/commands/v1/context"
        
        try:
            async with self._session.get(url, params=params, headers=self._headers) as response:
                if response.status == 200:
                    data = await response.json()
                    return data.get("payload", {})
                elif response.status == 401:
                    # Token expiré, on essaie de le rafraîchir
                    await self.refresh_cognito_token_if_needed()
                    # On ne réessaie pas immédiatement pour éviter les boucles
                    return {}
                else:
                    _LOGGER.warning(f"Status HTTP {response.status} pour {mac_address}")
                    return {}
                    
        except asyncio.TimeoutError:
            _LOGGER.warning(f"Timeout pour {mac_address}")
            return {}
        except Exception as e:
            _LOGGER.warning(f"Erreur pour {mac_address}: {e}")
            return {}
    
    async def close(self):
        """Ferme la session"""
        if self._session:
            await self._session.close()

class HonDataExtractor:
    """Extracteur de données optimisé pour les commandes Jeedom"""
    
    def __init__(self, appliance_type):
        self.appliance_type = appliance_type
        
        # Mapping des paramètres les plus importants pour Jeedom
        self.key_parameters = {
            # Paramètres communs
            'machMode': 'machine_mode',
            'prCode': 'program_code', 
            'remainingTimeMM': 'remaining_time',
            'doorLockStatus': 'door_lock',
            'doorStatus': 'door_status',
            'errors': 'errors',
            'temp': 'temperature',
            'spinSpeed': 'spin_speed',
            'totalWashCycle': 'total_cycles',
            'currentWashCycle': 'current_cycle',
           # 'pause': 'pause_status',
            'prPhase': 'program_phase',
            'remoteCtrValid': 'remote_control',
           
            
            # Spécifiques lave-linge
            'totalWaterUsed': 'total_water_used',
            'currentWaterUsed': 'current_water_used',
            'totalElectricityUsed': 'total_electricity_used',
            'currentElectricityUsed': 'current_electricity_used',
            'actualWeight': 'estimated_weight',
            'autoDetergentStatus': 'auto_detergent',
            'autoSoftenerStatus': 'auto_softener',
            
            # Spécifiques sèche-linge
            'dryLevel': 'dry_level',
            'sterilizationStatus': 'sterilization_status'
        }
    
    def extract_essential_data(self, context):
        """Extrait seulement les données essentielles pour optimiser la performance"""
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
                    
                    # Conversion des valeurs selon le type
                    converted_value = self._convert_value(api_key, value)
                    
                    extracted[jeedom_key] = {
                        'value': converted_value,
                        'last_update': last_update,
                        'api_key': api_key
                    }
        
        # Ajouter quelques calculs simples
        extracted.update(self._calculate_derived_values(extracted, context))
        
        return extracted
    
    def _convert_value(self, key, value):
        """Convertit les valeurs selon leur type"""
        try:
            # Valeurs numériques
            if key in ['remainingTimeMM', 'temp', 'spinSpeed', 'totalWashCycle', 
                      'currentWashCycle', 'dryLevel', 'actualWeight']:
                return int(value) if value != '' else 0
            
            # Valeurs booléennes
            elif key in ['doorLockStatus', 'doorStatus', 'pause', 'remoteCtrValid',
                        'autoDetergentStatus', 'autoSoftenerStatus', 'sterilizationStatus']:
                return value == "1"
            
            # Valeurs flottantes
            elif key in ['totalWaterUsed', 'currentWaterUsed', 'totalElectricityUsed', 
                        'currentElectricityUsed']:
                return round(float(value) / 100.0, 2) if value != '' else 0.0
            
            # Valeurs texte
            else:
                return str(value)
                
        except (ValueError, TypeError):
            return value
    
    def _calculate_derived_values(self, extracted, context):
        """Calcule quelques valeurs dérivées importantes"""
        derived = {}
        
        # Statut global de la machine
        if 'machine_mode' in extracted:
            mode = extracted['machine_mode']['value']
            status_map = {
                0: "Arret", 1: "Prêt", 2: "En cours", 3: "Pause",
                4: "Programmé", 5: "Erreur", 6: "Terminé", 7: "Terminé"
            }
            derived['status'] = {
                'value': status_map.get(int(mode), "Unknown"),
                'last_update': datetime.now(timezone.utc).isoformat(),
                'api_key': 'calculated'
            }
        
        # Heure de fin estimée
        if 'remaining_time' in extracted and extracted['remaining_time']['value'] > 0:
            end_time = datetime.now(timezone.utc) + timedelta(minutes=extracted['remaining_time']['value'])
            derived['estimated_end_time'] = {
                'value': end_time.isoformat(),
                'last_update': datetime.now(timezone.utc).isoformat(),
                'api_key': 'calculated'
            }
        
        # Statut de connexion
      #  last_conn = context.get("lastConnEvent", {})
      #  is_connected = last_conn.get("category") == "CONNECTED"
      #  derived['connection_status'] = {
       #     'value': "1" if is_connected else "0",
        #    'last_update': last_conn.get("instantTime", datetime.now(timezone.utc).isoformat()),
        #    'api_key': 'calculated'
      #  }
        
        return derived

async def update_single_device(connection, device_config, token_manager):
    """Met à jour un seul appareil"""
    mac_address = device_config['mac_address']
    appliance_type = device_config['appliance_type']
    
    try:
        # Récupération des données
        context = await connection.get_device_status(mac_address, appliance_type)
        
        if not context:
            return {
                'mac_address': mac_address,
                'success': False,
                'error': 'No data received'
            }
        
        # Extraction des données
        extractor = HonDataExtractor(appliance_type)
        extracted_data = extractor.extract_essential_data(context)
        
        return {
            'mac_address': mac_address,
            'appliance_type': appliance_type,
            'success': True,
            'data': extracted_data,
            'last_update': datetime.now(timezone.utc).isoformat(),
            'updated_cognito_token': connection._cognito_token
        }
        
    except Exception as e:
        return {
            'mac_address': mac_address,
            'success': False,
            'error': str(e)
        }

async def main():
    """Fonction principale optimisée pour le cron"""
    
    # Lecture des arguments (mac, type, tokens passés par PHP)
    if len(sys.argv) < 5:
        error_result = {
            'success': False,
            'error': 'Arguments manquants: mac_address appliance_type id_token cognito_token'
        }
        print(json.dumps(error_result))
        return
    
    mac_address = sys.argv[1]
    appliance_type = sys.argv[2]
    id_token = sys.argv[3]
    cognito_token = sys.argv[4]
    
    # Optionnel: configuration de plusieurs appareils
    devices_config = []
    
    if len(sys.argv) > 5 and sys.argv[5] == "multi":
        # Mode multi-appareils : les appareils suivants sont des paires mac:type
        for i in range(6, len(sys.argv), 2):
            if i + 1 < len(sys.argv):
                devices_config.append({
                    'mac_address': sys.argv[i],
                    'appliance_type': sys.argv[i + 1]
                })
    else:
        # Mode simple appareil
        devices_config.append({
            'mac_address': mac_address,
            'appliance_type': appliance_type
        })
    
    # Gestionnaire de tokens
    token_manager = HonTokenManager()
    
    # Connexion hOn
    connection = HonQuickConnection(id_token, cognito_token)
    
    results = []
    
    try:
        # Rafraîchir le token si nécessaire
        updated_token = await connection.refresh_cognito_token_if_needed()
        
        # Traitement de tous les appareils
        tasks = []
        for device_config in devices_config:
            task = update_single_device(connection, device_config, token_manager)
            tasks.append(task)
        
        # Exécution parallèle avec timeout global
        device_results = await asyncio.wait_for(
            asyncio.gather(*tasks, return_exceptions=True), 
            timeout=30
        )
        
        # Traitement des résultats
        for result in device_results:
            if isinstance(result, Exception):
                results.append({
                    'success': False,
                    'error': str(result)
                })
            else:
                results.append(result)
        
        # Sauvegarde du token mis à jour
        if updated_token and updated_token != cognito_token:
            token_data = {
                'id_token': id_token,
                'cognito_token': updated_token,
                'last_update': datetime.now(timezone.utc).isoformat()
            }
            token_manager.save_tokens(token_data)
        
        # Résultat final
        final_result = {
            'success': True,
            'devices': results,
            'total_devices': len(devices_config),
            'successful_updates': len([r for r in results if r.get('success', False)]),
            'execution_time': datetime.now(timezone.utc).isoformat(),
            'updated_tokens': {
                'cognito_token': connection._cognito_token
            } if updated_token != cognito_token else {}
        }
        
        print(json.dumps(final_result, ensure_ascii=False))
        
    except asyncio.TimeoutError:
        error_result = {
            'success': False,
            'error': 'Timeout - opération trop longue',
            'partial_results': results
        }
        print(json.dumps(error_result))
        
    except Exception as e:
        error_result = {
            'success': False,
            'error': f'Erreur générale: {str(e)}',
            'partial_results': results
        }
        print(json.dumps(error_result))
        
    finally:
        await connection.close()

if __name__ == "__main__":
    asyncio.run(main())
