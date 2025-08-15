#!/usr/bin/env python3
"""
Extracteur complet d'informations hOn par équipement
Créé un fichier JSON avec TOUTES les informations disponibles pour chaque appareil

Usage:
python3 hon_device_info.py <email> <password>

Génère:
- device_XX-XX-XX-XX-XX-XX.json pour chaque appareil (informations complètes)
- hon_devices_complete_index.json (index avec résumé)
"""

import asyncio
import aiohttp
import json
import logging
import sys
import secrets
import urllib.parse
import re
import os
from datetime import datetime, timezone

# Configuration des logs
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

class HonDeviceInfoExtractor:
    """Extracteur complet d'informations pour appareils hOn"""
    
    def __init__(self, email, password):
        self.email = email
        self.password = password
        self.mobile_id = secrets.token_hex(8)
        self.framework = "none"
        self.id_token = None
        self.cognito_token = None
        self.frontdoor_url = None
        self.appliances = []
        
        # Configuration API
        self.auth_api = "https://account2.hon-smarthome.com/SmartHome"
        self.api_url = "https://api-iot.he.services"
        self.app_version = "2.0.10"
        self.os_version = 31
        self.os = "android"
        self.device_model = "exynos9820"
        
        # Session HTTP
        self.headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36"
        }
        self.session = None

    async def __aenter__(self):
        self.session = aiohttp.ClientSession(
            headers=self.headers, 
            connector=aiohttp.TCPConnector(ssl=False)
        )
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        if self.session:
            await self.session.close()

    async def get_frontdoor_url(self):
        """Récupère l'URL frontdoor via Salesforce"""
        data = (
            "message=%7B%22actions%22%3A%5B%7B%22id%22%3A%2279%3Ba%22%2C%22descriptor%22%3A%22apex%3A%2F%2FLightningLoginCustomController%2FACTION%24login%22%2C%22callingDescriptor%22%3A%22markup%3A%2F%2Fc%3AloginForm%22%2C%22params%22%3A%7B%22username%22%3A%22"
            + urllib.parse.quote(self.email)
            + "%22%2C%22password%22%3A%22"
            + urllib.parse.quote(self.password)
            + "%22%2C%22startUrl%22%3A%22%22%7D%7D%5D%7D&aura.context=%7B%22mode%22%3A%22PROD%22%2C%22fwuid%22%3A%22"
            + urllib.parse.quote(self.framework)
            + "%22%2C%22app%22%3A%22siteforce%3AloginApp2%22%2C%22loaded%22%3A%7B%22APPLICATION%40markup%3A%2F%2Fsiteforce%3AloginApp2%22%3A%22YtNc5oyHTOvavSB9Q4rtag%22%7D%2C%22dn%22%3A%5B%5D%2C%22globals%22%3A%7B%7D%2C%22uad%22%3Afalse%7D&aura.pageURI=%2FSmartHome%2Fs%2Flogin%2F%3Flanguage%3Dfr&aura.token=null"
        )

        try:
            async with self.session.post(
                f"{self.auth_api}/s/sfsites/aura?r=3&other.LightningLoginCustom.login=1",
                headers={"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
                data=data
            ) as resp:
                if resp.status != 200:
                    return False

                text = await resp.text()
                try:
                    json_data = json.loads(text)
                    self.frontdoor_url = json_data["events"][0]["attributes"]["values"]["url"]
                    return True
                except:
                    if "clientOutOfSync" in text:
                        start = text.find("Expected: ") + 10
                        end = text.find(" ", start)
                        self.framework = text[start:end]
                        return await self.get_frontdoor_url()
                    return False
        except Exception:
            return False

    async def authorize(self):
        """Processus d'autorisation complet"""
        logger.info("🔐 Connexion à l'API hOn...")
        
        # 1. Récupération URL frontdoor
        if not await self.get_frontdoor_url():
            return False

        # 2. Suivi de la redirection
        try:
            async with self.session.get(self.frontdoor_url) as resp:
                if resp.status != 200:
                    return False
                await resp.text()
        except Exception:
            return False

        # 3. Login progressif
        try:
            url = f"{self.auth_api}/apex/ProgressiveLogin?retURL=%2FSmartHome%2Fapex%2FCustomCommunitiesLanding"
            async with self.session.get(url) as resp:
                await resp.text()
        except Exception:
            return False

        # 4. Récupération du token OAuth
        try:
            url = f"{self.auth_api}/services/oauth2/authorize?response_type=token+id_token&client_id=3MVG9QDx8IX8nP5T2Ha8ofvlmjLZl5L_gvfbT9.HJvpHGKoAS_dcMN8LYpTSYeVFCraUnV.2Ag1Ki7m4znVO6&redirect_uri=hon%3A%2F%2Fmobilesdk%2Fdetect%2Foauth%2Fdone&display=touch&scope=api%20openid%20refresh_token%20web&nonce=82e9f4d1-140e-4872-9fad-15e25fbf2b7c"
            async with self.session.get(url) as resp:
                text = await resp.text()
                
                m = re.search('id_token\\=(.+?)&', text)
                if m:
                    self.id_token = m.group(1)
                else:
                    return False
        except Exception:
            return False

        # 5. Échange contre le token Cognito
        try:
            post_headers = {"id-token": self.id_token}
            data = {
                "appVersion": self.app_version,
                "mobileId": self.mobile_id,
                "os": self.os,
                "osVersion": self.os_version,
                "deviceModel": self.device_model
            }

            async with self.session.post(f"{self.api_url}/auth/v1/login", headers=post_headers, json=data) as resp:
                json_data = await resp.json()
                self.cognito_token = json_data["cognitoUser"]["Token"]
        except Exception:
            return False

        # 6. Récupération des appareils
        try:
            headers = {
                "Content-Type": "application/json",
                "cognito-token": self.cognito_token,
                "id-token": self.id_token,
            }
            
            url = f"{self.api_url}/commands/v1/appliance"
            async with self.session.get(url, headers=headers) as resp:
                json_data = await resp.json()
                self.appliances = json_data["payload"]["appliances"]
                self.appliances = [a for a in self.appliances if "macAddress" in a and "applianceTypeId" in a]
                
                logger.info(f"✅ Connexion réussie - {len(self.appliances)} appareils trouvés")
                return True
        except Exception:
            return False

    @property
    def api_headers(self):
        return {
            "Content-Type": "application/json",
            "cognito-token": self.cognito_token,
            "id-token": self.id_token,
        }

    async def get_appliance_commands(self, appliance):
        """Récupère les commandes d'un appareil"""
        params = {
            "applianceType": appliance["applianceTypeId"],
            "code": appliance["code"],
            "applianceModelId": appliance["applianceModelId"],
            "firmwareId": appliance["eepromId"],
            "macAddress": appliance["macAddress"],
            "fwVersion": appliance["fwVersion"],
            "os": self.os,
            "appVersion": self.app_version,
            "series": appliance["series"],
        }
        
        try:
            url = f"{self.api_url}/commands/v1/retrieve"
            async with self.session.get(url, params=params, headers=self.api_headers) as resp:
                result = (await resp.json()).get("payload", {})
                if result and result.get("resultCode") == "0":
                    result.pop("resultCode", None)
                    return result
                return {}
        except Exception as e:
            logger.error(f"❌ Erreur récupération commandes: {e}")
            return {}

    async def get_appliance_context(self, appliance):
        """Récupère le contexte/état actuel de l'appareil"""
        params = {
            "macAddress": appliance["macAddress"],
            "applianceType": appliance["applianceTypeName"],
            "category": "CYCLE"
        }
        
        try:
            url = f"{self.api_url}/commands/v1/context"
            async with self.session.get(url, params=params, headers=self.api_headers) as resp:
                data = await resp.json()
                return data.get("payload", {})
        except Exception as e:
            logger.error(f"❌ Erreur récupération contexte: {e}")
            return {}

    async def get_appliance_statistics(self, appliance):
        """Récupère les statistiques de l'appareil"""
        params = {
            "macAddress": appliance["macAddress"],
            "applianceType": appliance["applianceTypeName"]
        }
        
        try:
            url = f"{self.api_url}/commands/v1/statistics"
            async with self.session.get(url, params=params, headers=self.api_headers) as resp:
                data = await resp.json()
                return data.get("payload", {})
        except Exception as e:
            logger.error(f"❌ Erreur récupération statistiques: {e}")
            return {}

    def get_appliance_type_name(self, type_id):
        """Retourne le nom du type d'appareil"""
        types = {
            "1": "Machine à laver",
            "2": "Lave-linge séchant", 
            "4": "Four",
            "6": "Cave à vin",
            "7": "Purificateur d'air",
            "8": "Sèche-linge",
            "9": "Lave-vaisselle",
            "11": "Climatisation",
            "14": "Réfrigérateur"
        }
        return types.get(str(type_id), f"Type {type_id}")

    def analyze_commands_structure(self, commands):
        """Analyse la structure des commandes pour extraire des informations (sans les programmes PROGRAMS)"""
        analysis = {
            "total_commands": len(commands),
            "command_types": [],
            "programs_count": 0,
            "settings_available": False,
            "command_details": {}
        }
        
        for cmd_name, cmd_data in commands.items():
            if cmd_name in ["applianceModel", "options", "dictionaryId"]:
                continue
                
            analysis["command_types"].append(cmd_name)
            
            cmd_detail = {
                "name": cmd_name,
                "type": "unknown",
                "parameters_count": 0,
                "has_programs": False
            }
            
            if cmd_name == "startProgram" and isinstance(cmd_data, dict):
                # Ne pas inclure les programmes qui commencent par "PROGRAMS"
                programs = [k for k in cmd_data.keys() if k != "setParameters" and not k.startswith("PROGRAMS")]
                analysis["programs_count"] = len(programs)
                cmd_detail["has_programs"] = True
                cmd_detail["type"] = "program_launcher"
                cmd_detail["programs"] = programs[:10]  # Limite à 10 pour l'affichage
                
            elif cmd_name == "settings":
                analysis["settings_available"] = True
                cmd_detail["type"] = "settings"
                if isinstance(cmd_data, dict) and "parameters" in cmd_data:
                    cmd_detail["parameters_count"] = len(cmd_data["parameters"])
                    
            elif isinstance(cmd_data, dict) and "parameters" in cmd_data:
                cmd_detail["type"] = "command_with_params"
                cmd_detail["parameters_count"] = len(cmd_data["parameters"])
            
            analysis["command_details"][cmd_name] = cmd_detail
        
        return analysis

    def filter_programs_from_commands(self, commands):
        """Filtre les commandes pour exclure les programmes qui commencent par PROGRAMS"""
        filtered_commands = {}
        
        for cmd_name, cmd_data in commands.items():
            if cmd_name == "startProgram" and isinstance(cmd_data, dict):
                # Créer une copie filtrée de startProgram
                filtered_start_program = {}
                for prog_key, prog_data in cmd_data.items():
                    # Garder setParameters et exclure les programmes PROGRAMS.*
                    if prog_key == "setParameters" or not prog_key.startswith("PROGRAMS"):
                        filtered_start_program[prog_key] = prog_data
                filtered_commands[cmd_name] = filtered_start_program
            else:
                # Garder les autres commandes telles quelles
                filtered_commands[cmd_name] = cmd_data
                
        return filtered_commands

    def extract_all_appliance_info(self, appliance):
        """Extrait toutes les informations brutes de l'appareil"""
        info = {
            "raw_appliance_data": appliance,
            "extracted_info": {
                "name": appliance.get('nickName', 'Sans nom'),
                "mac_address": appliance.get('macAddress', 'Unknown'),
                "brand": appliance.get('brand', 'Unknown'),
                "model": appliance.get('modelName', 'Unknown'),
                "type_id": appliance.get('applianceTypeId', 'Unknown'),
                "type_name": self.get_appliance_type_name(appliance.get('applianceTypeId', 0)),
                "firmware_version": appliance.get('fwVersion', 'Unknown'),
                "series": appliance.get('series', 'Unknown'),
                "code": appliance.get('code', 'Unknown'),
                "model_id": appliance.get('applianceModelId', 'Unknown'),
                "eeprom_id": appliance.get('eepromId', 'Unknown'),
                "serial_number": appliance.get('serialNumber', 'Unknown')
            },
            "all_available_fields": list(appliance.keys())
        }
        
        return info

    async def generate_complete_device_info(self):
        """Génère des fichiers JSON complets avec toutes les informations"""
        if not self.appliances:
            logger.error("❌ Aucun appareil trouvé")
            return False

        logger.info(f"📊 Extraction complète des informations pour {len(self.appliances)} appareils...")
        generated_files = []
        
        # Créer le répertoire de destination
        output_dir = "hon/data"
        os.makedirs(output_dir, exist_ok=True)
        
        for appliance in self.appliances:
            mac_address = appliance['macAddress'].replace(':', '-')
            device_name = appliance.get('nickName', f"Device_{appliance['applianceTypeId']}")
            device_type = self.get_appliance_type_name(appliance['applianceTypeId'])
            
            logger.info(f"🔍 Extraction complète: {device_name} ({device_type}) - MAC: {appliance['macAddress']}")
            
            # Extraction de toutes les informations
            device_info = self.extract_all_appliance_info(appliance)
            
            # Récupération des commandes et filtrage
            commands = await self.get_appliance_commands(appliance)
            filtered_commands = self.filter_programs_from_commands(commands)
            commands_analysis = self.analyze_commands_structure(filtered_commands)
            
            # Récupération du contexte/état
            context = await self.get_appliance_context(appliance)
            
            # Récupération des statistiques
            statistics = await self.get_appliance_statistics(appliance)
            
            # Structure complète du JSON
            complete_data = {
                "extraction_info": {
                    "extracted_at": datetime.now().isoformat(),
                    "extractor_version": "1.0",
                    "api_endpoints_used": [
                        "/commands/v1/appliance",
                        "/commands/v1/retrieve", 
                        "/commands/v1/context",
                        "/commands/v1/statistics"
                    ]
                },
                "device_identification": device_info,
                "commands": {
                    "raw_commands_data": filtered_commands,  # Commandes filtrées sans PROGRAMS.*
                    "original_commands_count": len(commands),  # Nombre original avant filtrage
                    "filtered_commands_count": len(filtered_commands),
                    "commands_analysis": commands_analysis,
                    "available_commands": list(filtered_commands.keys()),
                    "filtering_info": {
                        "excluded_pattern": "PROGRAMS.*",
                        "description": "Programmes commençant par PROGRAMS ont été exclus"
                    }
                },
                "current_state": {
                    "context": context,
                    "statistics": statistics
                },
                "connectivity": {
                    "mac_address": appliance['macAddress'],
                    "last_connected": context.get("attributes", {}).get("lastConnEvent", {}),
                    "connection_status": "unknown"
                }
            }
            
            # Analyse de la connectivité
            if context.get("attributes", {}).get("lastConnEvent", {}).get("category") == "CONNECTED":
                complete_data["connectivity"]["connection_status"] = "connected"
            elif context.get("attributes", {}).get("lastConnEvent", {}).get("category") == "DISCONNECTED":
                complete_data["connectivity"]["connection_status"] = "disconnected"
            
            # Nom du fichier JSON basé sur device_MAC
            json_filename = f"device_{mac_address}.json"
            json_filepath = os.path.join(output_dir, json_filename)
            
            # Sauvegarde du JSON
            try:
                with open(json_filepath, 'w', encoding='utf-8') as f:
                    json.dump(complete_data, f, indent=2, ensure_ascii=False)
                
                # Changer les permissions
                import stat
                os.chmod(json_filepath, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)
                
                logger.info(f"✅ JSON complet généré: {json_filepath}")
                
                generated_files.append({
                    "file": json_filepath,
                    "filename": json_filename,
                    "device": device_name,
                    "mac": appliance['macAddress'],
                    "mac_filename": mac_address,
                    "type": device_type,
                    "commands_count": commands_analysis["total_commands"],
                    "programs_count": commands_analysis["programs_count"],
                    "connection_status": complete_data["connectivity"]["connection_status"]
                })
                
            except Exception as e:
                logger.error(f"❌ Erreur sauvegarde JSON pour {device_name}: {e}")
                continue

        # Génération d'un fichier index complet
        if generated_files:
            index_data = {
                "extraction_info": {
                    "extracted_at": datetime.now().isoformat(),
                    "total_devices": len(generated_files),
                    "extractor_version": "1.0"
                },
                "summary": {
                    "devices_by_type": {},
                    "total_commands": 0,
                    "total_programs": 0,
                    "connection_status": {"connected": 0, "disconnected": 0, "unknown": 0}
                },
                "devices": generated_files
            }
            
            # Calculs des statistiques
            for device in generated_files:
                device_type = device["type"]
                index_data["summary"]["devices_by_type"][device_type] = index_data["summary"]["devices_by_type"].get(device_type, 0) + 1
                index_data["summary"]["total_commands"] += device["commands_count"]
                index_data["summary"]["total_programs"] += device["programs_count"]
                index_data["summary"]["connection_status"][device["connection_status"]] += 1
            
            index_filepath = os.path.join(output_dir, "hon_devices_complete_index.json")
            try:
                with open(index_filepath, 'w', encoding='utf-8') as f:
                    json.dump(index_data, f, indent=2, ensure_ascii=False)
                
                import stat
                os.chmod(index_filepath, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)
                
                logger.info(f"📋 Index complet généré: {index_filepath}")
                
                # Affichage du résumé détaillé
                print(f"\n📊 RÉSUMÉ DE L'EXTRACTION COMPLÈTE:")
                print("="*80)
                
                for device_info in generated_files:
                    print(f"📱 {device_info['device']} ({device_info['type']})")
                    print(f"   MAC: {device_info['mac']}")
                    print(f"   Fichier: {device_info['filename']}")
                    print(f"   Commandes: {device_info['commands_count']}")
                    print(f"   Programmes: {device_info['programs_count']}")
                    print(f"   Statut: {device_info['connection_status']}")
                    print()
                
                print(f"📈 STATISTIQUES GLOBALES:")
                print(f"   Total appareils: {index_data['summary']['total_devices']}")
                print(f"   Total commandes: {index_data['summary']['total_commands']}")
                print(f"   Total programmes: {index_data['summary']['total_programs']}")
                print(f"   Connectés: {index_data['summary']['connection_status']['connected']}")
                print(f"   Déconnectés: {index_data['summary']['connection_status']['disconnected']}")
                
                print(f"\n💾 Fichiers générés dans {output_dir}/:")
                for device_info in generated_files:
                    print(f"  • {device_info['filename']} (informations complètes)")
                print(f"  • hon_devices_complete_index.json (index détaillé)")
                
                print(f"\n💡 Ces fichiers contiennent:")
                print(f"  • Toutes les informations brutes de l'appareil")
                print(f"  • Analyse complète des commandes disponibles (sans PROGRAMS.*)")
                print(f"  • État actuel et statistiques")
                print(f"  • Informations de connectivité")
                print(f"  • Filtrage automatique des programmes PROGRAMS.*")
                
            except Exception as e:
                logger.error(f"❌ Erreur génération index: {e}")

        return len(generated_files) > 0


async def main():
    """Fonction principale"""
    if len(sys.argv) != 3:
        print("📊 EXTRACTEUR COMPLET D'INFORMATIONS hOn")
        print("="*60)
        print("Usage: python3 hon_device_info.py <email> <password>")
        print("\nCe script extrait TOUTES les informations disponibles:")
        print("  • Informations complètes de l'appareil")
        print("  • Toutes les commandes et leurs paramètres (sans programmes PROGRAMS.*)")
        print("  • État actuel et contexte")
        print("  • Statistiques d'utilisation")
        print("  • Informations de connectivité")
        print("\nExemple:")
        print("  python3 hon_device_info.py email@domain.com password123")
        print("\nFichiers générés:")
        print("  • device_XX-XX-XX-XX-XX-XX.json (par appareil)")
        print("  • hon_devices_complete_index.json (index détaillé)")
        print("\nEmplacement:")
        print("  • hon/data/ (nouveau répertoire)")
        sys.exit(1)
    
    email = sys.argv[1]
    password = sys.argv[2]
    
    try:
        async with HonDeviceInfoExtractor(email, password) as extractor:
            if not await extractor.authorize():
                logger.error("❌ Échec de l'autorisation")
                return 1
            
            success = await extractor.generate_complete_device_info()
            
            if success:
                print(f"\n🎉 EXTRACTION COMPLÈTE TERMINÉE AVEC SUCCÈS!")
                print(f"💡 Consultez les fichiers JSON pour toutes les informations détaillées")
                return 0
            else:
                print(f"\n❌ ÉCHEC DE L'EXTRACTION")
                return 1
                
    except Exception as e:
        logger.error(f"❌ Erreur fatale: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))