#!/usr/bin/env python3
"""
Générateur JSON des programmes hOn par équipement
Créé un fichier JSON séparé pour chaque programme de chaque appareil, nommé par MAC_programme.json

Usage:
python3 hon_json_generator.py <email> <password>

Génère:
- MAC_programme.json pour chaque programme de chaque appareil
- hon_devices_index.json (index de tous les appareils et programmes)
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

class HonDeviceJsonGenerator:
    """Générateur de fichiers JSON par programme d'appareil hOn"""
    
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
        except Exception:
            return {}

    def parse_parameter_info(self, param_name, param_info):
        """Parse les informations d'un paramètre"""
        param_type = param_info.get("typology", "unknown")
        category = param_info.get("category", "")
        mandatory = param_info.get("mandatory", False)
        
        result = {
            "name": param_name,
            "type": param_type,
            "category": category,
            "mandatory": mandatory,
            "description": ""
        }
        
        if param_type == "fixed":
            result["value"] = param_info.get("fixedValue", "0")
            result["description"] = f"Valeur fixe: {result['value']}"
            
        elif param_type == "enum":
            result["values"] = param_info.get("enumValues", [])
            result["default"] = param_info.get("defaultValue", "")
            result["description"] = f"Choix: {result['values']} (défaut: {result['default']})"
            
        elif param_type == "range":
            result["min"] = param_info.get("minimumValue", "0")
            result["max"] = param_info.get("maximumValue", "100")
            result["step"] = param_info.get("incrementValue", "1")
            result["default"] = param_info.get("defaultValue", result["min"])
            result["description"] = f"Plage: [{result['min']}-{result['max']}] pas:{result['step']} (défaut: {result['default']})"
        
        return result

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

    async def generate_device_json_files(self):
        """Génère un fichier JSON séparé pour chaque programme de chaque appareil trouvé"""
        if not self.appliances:
            logger.error("❌ Aucun appareil trouvé")
            return False

        logger.info(f"📊 Génération des JSON pour {len(self.appliances)} appareils...")
        generated_files = []
        
        # Créer le répertoire de destination
        output_dir = "hon/data"
        os.makedirs(output_dir, exist_ok=True)
        
        for appliance in self.appliances:
            mac_address = appliance['macAddress'].replace(':', '-')  # Remplacer : par - pour nom de fichier
            device_name = appliance.get('nickName', f"Device_{appliance['applianceTypeId']}")
            device_type = self.get_appliance_type_name(appliance['applianceTypeId'])
            
            logger.info(f"🔍 Analyse de: {device_name} ({device_type}) - MAC: {appliance['macAddress']}")
            
            commands = await self.get_appliance_commands(appliance)
            if not commands:
                logger.warning(f"⚠️ Aucune commande récupérée pour {device_name}")
                continue

            device_info = {
                "name": device_name,
                "mac": appliance['macAddress'],
                "mac_filename": mac_address,
                "brand": appliance['brand'],
                "model": appliance['modelName'],
                "type_id": appliance['applianceTypeId'],
                "type_name": device_type,
                "firmware": appliance['fwVersion'],
                "series": appliance.get('series', ''),
                "generated_at": datetime.now().isoformat()
            }

            # Traitement des programmes (si startProgram existe)
            programs_generated = 0
            if "startProgram" in commands:
                start_programs = commands["startProgram"]
                
                for program_key, program_data in start_programs.items():
                    if program_key in ["setParameters"]:
                        continue
                        
                    program_name = program_key.split(".")[-1].lower()
                    
                    # Structure du programme pour ce fichier JSON spécifique
                    program_json = {
                        "device_info": device_info,
                        "program_info": {
                            "display_name": program_name.replace('_', ' ').title(),
                            "key": program_key,
                            "name": program_name,
                            "description": f"Programme {program_name} pour {device_name}",
                            "parameters": {},
                            "default_parameters": {},
                            "configurable_parameters": [],
                            "fixed_parameters": []
                        }
                    }
                    
                    if isinstance(program_data, dict) and "parameters" in program_data:
                        params = program_data["parameters"]
                        
                        for param_name, param_info in params.items():
                            # Parse les informations du paramètre
                            parsed_param = self.parse_parameter_info(param_name, param_info)
                            program_json["program_info"]["parameters"][param_name] = parsed_param
                            
                            # Génère la valeur par défaut
                            if parsed_param["type"] == "fixed":
                                program_json["program_info"]["default_parameters"][param_name] = parsed_param["value"]
                                program_json["program_info"]["fixed_parameters"].append(param_name)
                            elif parsed_param["type"] in ["enum", "range"]:
                                program_json["program_info"]["default_parameters"][param_name] = parsed_param["default"]
                                program_json["program_info"]["configurable_parameters"].append(param_name)
                            else:
                                program_json["program_info"]["default_parameters"][param_name] = "0"
                        
                        # Ajout du paramètre obligatoire de démarrage
                        program_json["program_info"]["default_parameters"]["onOffStatus"] = "1"
                    
                    # Nom du fichier JSON basé sur MAC_programme
                    json_filename = f"{mac_address}_{program_name}.json"
                    json_filepath = os.path.join(output_dir, json_filename)
                    
                    # Sauvegarde du JSON
                    try:
                        with open(json_filepath, 'w', encoding='utf-8') as f:
                            json.dump(program_json, f, indent=2, ensure_ascii=False)
                        
                        # Changer les permissions pour lecture/écriture pour tous
                        import stat
                        os.chmod(json_filepath, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)
                        
                        programs_generated += 1
                        generated_files.append({
                            "file": json_filepath,
                            "filename": json_filename,
                            "device": device_name,
                            "mac": appliance['macAddress'],
                            "mac_filename": mac_address,
                            "type": device_type,
                            "program": program_name,
                            "program_display": program_name.replace('_', ' ').title(),
                            "parameters_count": len(program_json["program_info"]["parameters"])
                        })
                        
                    except Exception as e:
                        logger.error(f"❌ Erreur sauvegarde JSON pour {device_name}/{program_name}: {e}")
                        continue

                logger.info(f"   ✅ {programs_generated} programmes générés pour {device_name}")
            else:
                logger.info(f"   ℹ️ Pas de programmes startProgram pour cet appareil")

        # Génération d'un fichier index
        if generated_files:
            index_data = {
                "generated_at": datetime.now().isoformat(),
                "total_programs": len(generated_files),
                "total_devices": len(set(f["mac"] for f in generated_files)),
                "programs": generated_files,
                "devices_summary": {}
            }
            
            # Résumé par appareil
            for device_mac in set(f["mac"] for f in generated_files):
                device_files = [f for f in generated_files if f["mac"] == device_mac]
                device_info = device_files[0]  # Prendre les infos du premier fichier
                index_data["devices_summary"][device_mac] = {
                    "name": device_info["device"],
                    "type": device_info["type"],
                    "mac_filename": device_info["mac_filename"],
                    "programs_count": len(device_files),
                    "programs": [f["program"] for f in device_files]
                }
            
            index_filepath = os.path.join(output_dir, "hon_devices_index.json")
            try:
                with open(index_filepath, 'w', encoding='utf-8') as f:
                    json.dump(index_data, f, indent=2, ensure_ascii=False)
                
                import stat
                os.chmod(index_filepath, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)
                
                logger.info(f"📋 Index généré: {index_filepath}")
                
                # Affichage du résumé
                print(f"\n📊 RÉSUMÉ DE LA GÉNÉRATION:")
                print("="*80)
                for mac, device_summary in index_data["devices_summary"].items():
                    print(f"📱 {device_summary['name']} ({device_summary['type']})")
                    print(f"   MAC: {mac}")
                    print(f"   Programmes: {device_summary['programs_count']}")
                    for program in device_summary['programs']:
                        print(f"     • {device_summary['mac_filename']}_{program}.json")
                    print()
                
                print(f"💾 {len(generated_files)} fichiers JSON générés dans {output_dir}/:")
                for device_info in generated_files:
                    print(f"  • {device_info['filename']}")
                print(f"  • hon_devices_index.json (index)")
                
                print(f"\n💡 Utilisation avec le lanceur:")
                print(f"  python3 hon_launcher.py email password devices")
                example_file = generated_files[0]
                print(f"  python3 hon_launcher.py email password list {example_file['mac']}")
                print(f"  python3 hon_launcher.py email password {example_file['program']} {example_file['mac']}")
                
            except Exception as e:
                logger.error(f"❌ Erreur génération index: {e}")

        return len(generated_files) > 0


async def main():
    """Fonction principale"""
    if len(sys.argv) != 3:
        print("📊 GÉNÉRATEUR JSON hOn PAR PROGRAMME")
        print("="*50)
        print("Usage: python3 hon_json_generator.py <email> <password>")
        print("\nCe script génère:")
        print("  • Un fichier JSON par programme de chaque appareil")
        print("  • Nommage: MAC_programme.json")
        print("  • Un fichier index de tous les appareils et programmes")
        print("\nExemple:")
        print("  python3 hon_json_generator.py email@domain.com password123")
        print("\nFichiers générés:")
        print("  • AA-BB-CC-DD-EE-FF_cottons.json")
        print("  • AA-BB-CC-DD-EE-FF_synthetics.json")
        print("  • hon_devices_index.json (index général)")
        print("\nEmplacement:")
        print("  • hon/data/ (nouveau répertoire)")
        sys.exit(1)
    
    email = sys.argv[1]
    password = sys.argv[2]
    
    try:
        async with HonDeviceJsonGenerator(email, password) as generator:
            if not await generator.authorize():
                logger.error("❌ Échec de l'autorisation")
                return 1
            
            success = await generator.generate_device_json_files()
            
            if success:
                print(f"\n🎉 GÉNÉRATION TERMINÉE AVEC SUCCÈS!")
                print(f"💡 Utilisez maintenant le lanceur pour contrôler vos appareils")
                return 0
            else:
                print(f"\n❌ ÉCHEC DE LA GÉNÉRATION")
                return 1
                
    except Exception as e:
        logger.error(f"❌ Erreur fatale: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))