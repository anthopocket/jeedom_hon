#!/usr/bin/env python3
"""
Lanceur de programmes machine à laver hOn
Utilise les fichiers JSON pré-générés par MAC et programme (hon/data/)

Usage:
python3 hon_launcher.py <email> <password> <command> <mac_address> [parametres]

Commandes:
devices                     - Liste tous les appareils disponibles
list <mac>                  - Liste les programmes d'un appareil
<program_name> <mac>        - Lance un programme sur un appareil
pause <mac>                 - Met en pause le programme en cours
resume <mac>                - Reprend le programme en pause
stop <mac>                  - Arrête le programme en cours
stopmachine <mac>           - Arrête complètement la machine

Exemples:
python3 hon_launcher.py email@domain.com password123 devices
python3 hon_launcher.py email@domain.com password123 list AA:BB:CC:DD:EE:FF
python3 hon_launcher.py email@domain.com password123 cottons AA:BB:CC:DD:EE:FF
python3 hon_launcher.py email@domain.com password123 pause AA:BB:CC:DD:EE:FF
python3 hon_launcher.py email@domain.com password123 resume AA:BB:CC:DD:EE:FF
python3 hon_launcher.py email@domain.com password123 stop AA:BB:CC:DD:EE:FF
python3 hon_launcher.py email@domain.com password123 cottons AA:BB:CC:DD:EE:FF temp=30,spinSpeed=1200
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
import glob
from datetime import datetime, timezone

# Configuration des logs
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Répertoire des données JSON
DATA_DIR = "hon/data"

class HonLauncher:
    """Lanceur rapide de programmes hOn basé sur les JSON par MAC/programme"""
    
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

    def mac_to_filename(self, mac_address):
        """Convertit une adresse MAC en format de nom de fichier"""
        return mac_address.replace(':', '-')

    def filename_to_mac(self, filename):
        """Convertit un nom de fichier en adresse MAC"""
        return filename.replace('-', ':')

    def load_devices_index(self):
        """Charge l'index des appareils"""
        index_path = os.path.join(DATA_DIR, "hon_devices_index.json")
        if not os.path.exists(index_path):
            logger.error(f"❌ Index des appareils non trouvé: {index_path}")
            logger.error("💡 Générez d'abord les fichiers avec: python3 hon_json_generator.py <email> <password>")
            return None
            
        try:
            with open(index_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            logger.error(f"❌ Erreur chargement index: {e}")
            return None

    def list_devices(self):
        """Affiche la liste des appareils disponibles"""
        index_data = self.load_devices_index()
        if not index_data:
            return False
            
        print(f"\n📱 APPAREILS hOn DISPONIBLES:")
        print("="*80)
        
        for device_info in index_data['devices']:
            print(f"📱 {device_info['device']} ({device_info['type']})")
            print(f"   MAC: {device_info['mac']}")
            print(f"   Programmes: {device_info['programs']}")
            print(f"   Paramètres: {device_info['settings']}")
            print()
        
        print(f"💡 Usage:")
        print(f"   python3 hon_launcher.py email password list {index_data['devices'][0]['mac']}")
        print(f"   python3 hon_launcher.py email password <programme> {index_data['devices'][0]['mac']}")
        
        return True

    def list_programs_for_device(self, mac_address):
        """Affiche la liste des programmes pour un appareil donné"""
        mac_filename = self.mac_to_filename(mac_address)
        
        # Recherche de tous les fichiers pour cette MAC
        pattern = os.path.join(DATA_DIR, f"{mac_filename}_*.json")
        program_files = glob.glob(pattern)
        
        if not program_files:
            logger.error(f"❌ Aucun programme trouvé pour l'appareil MAC: {mac_address}")
            logger.error(f"   Recherche dans: {pattern}")
            return False
        
        print(f"\n📱 PROGRAMMES POUR APPAREIL: {mac_address}")
        print("="*80)
        
        # Charge les informations de l'appareil depuis le premier fichier
        try:
            with open(program_files[0], 'r', encoding='utf-8') as f:
                first_program = json.load(f)
                device_info = first_program['device_info']
                
            print(f"Appareil: {device_info['name']} ({device_info['type_name']})")
            print(f"Modèle: {device_info['brand']} {device_info['model']}")
            print(f"MAC: {device_info['mac']}")
            print()
        except Exception as e:
            logger.warning(f"⚠️ Erreur lecture info appareil: {e}")
        
        # Liste tous les programmes
        programs_info = []
        for file_path in program_files:
            try:
                filename = os.path.basename(file_path)
                # Extrait le nom du programme du nom de fichier
                program_name = filename.replace(f"{mac_filename}_", "").replace(".json", "")
                
                with open(file_path, 'r', encoding='utf-8') as f:
                    program_data = json.load(f)
                    program_info = program_data['program_info']
                    
                programs_info.append({
                    'name': program_name,
                    'display': program_info['display_name'],
                    'configurable': len(program_info['configurable_parameters']),
                    'key_params': [p for p in ['temp', 'spinSpeed', 'mainWashTime'] if p in program_info['configurable_parameters']]
                })
                
            except Exception as e:
                logger.warning(f"⚠️ Erreur lecture {file_path}: {e}")
                continue
        
        # Affichage des programmes
        if programs_info:
            # Programmes principaux d'abord
            main_programs = ['cottons', 'quick_15', 'smart', 'synthetic_and_coloured']
            main_found = [p for p in programs_info if any(main in p['name'] for main in main_programs)]
            other_programs = [p for p in programs_info if p not in main_found]
            
            if main_found:
                print("⭐ Programmes principaux:")
                for prog in main_found:
                    key_params = ", ".join(prog['key_params']) if prog['key_params'] else "paramètres fixes"
                    print(f"  • {prog['name']:<25} - {prog['display']} ({key_params})")
                print()
            
            if other_programs:
                print(f"📋 Autres programmes ({len(other_programs)}):")
                for prog in other_programs[:10]:  # Limite à 10
                    print(f"  • {prog['name']:<25} - {prog['display']} ({prog['configurable']} param. config.)")
                
                if len(other_programs) > 10:
                    print(f"  ... et {len(other_programs) - 10} autres programmes")
        
        print(f"\n💡 Usage:")
        print(f"   python3 hon_launcher.py email password <programme> {mac_address} [params]")
        print(f"   Exemple: python3 hon_launcher.py email password cottons {mac_address} temp=30,spinSpeed=1200")
        
        return True

    def load_program_json(self, mac_address, program_name):
        """Charge le fichier JSON d'un programme spécifique"""
        mac_filename = self.mac_to_filename(mac_address)
        json_path = os.path.join(DATA_DIR, f"{mac_filename}_{program_name}.json")
        
        if not os.path.exists(json_path):
            logger.error(f"❌ Programme '{program_name}' non trouvé pour l'appareil {mac_address}")
            logger.error(f"   Fichier recherché: {json_path}")
            return None
            
        try:
            with open(json_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            logger.error(f"❌ Erreur chargement JSON: {e}")
            return None

    def parse_custom_parameters(self, param_string):
        """Parse les paramètres personnalisés depuis la ligne de commande"""
        custom_params = {}
        if param_string:
            try:
                # Format: temp=30,spinSpeed=1200,mainWashTime=20
                pairs = param_string.split(',')
                for pair in pairs:
                    if '=' in pair:
                        key, value = pair.split('=', 1)
                        custom_params[key.strip()] = value.strip()
            except Exception as e:
                logger.warning(f"⚠️ Erreur parsing paramètres: {e}")
        return custom_params

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
        """Processus d'autorisation rapide"""
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

        logger.info("✅ Connexion réussie!")
        return True

    @property
    def api_headers(self):
        return {
            "Content-Type": "application/json",
            "cognito-token": self.cognito_token,
            "id-token": self.id_token,
        }

    def find_appliance_by_mac(self, mac_address):
        """Trouve un appareil par son adresse MAC depuis les appareils connectés"""
        # Note: Cette méthode nécessiterait une requête API pour récupérer les appareils
        # Pour simplifier, on retourne un objet factice basé sur les données JSON
        program_data = self.load_program_json(mac_address, "cottons")  # Essaie avec un programme courant
        if not program_data:
            # Essaie de trouver n'importe quel programme pour cet appareil
            mac_filename = self.mac_to_filename(mac_address)
            pattern = os.path.join(DATA_DIR, f"{mac_filename}_*.json")
            files = glob.glob(pattern)
            if files:
                try:
                    with open(files[0], 'r', encoding='utf-8') as f:
                        program_data = json.load(f)
                except:
                    return None
            else:
                return None
        
        device_info = program_data['device_info']
        return {
            "macAddress": device_info['mac'],
            "nickName": device_info['name'],
            "applianceTypeId": device_info['type_id'],
            "applianceTypeName": device_info.get('type_name', 'WM')  # Default WM pour machine à laver
        }

    async def send_command(self, appliance, command_name, parameters):
        """Envoie une commande à un appareil"""
        timestamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        
        command = {
            "macAddress": appliance["macAddress"],
            "timestamp": timestamp,
            "commandName": command_name,
            "transactionId": f"{appliance['macAddress']}_{timestamp}",
            "applianceOptions": {},
            "device": {
                "mobileId": self.mobile_id,
                "mobileOs": self.os,
                "osVersion": self.os_version,
                "appVersion": self.app_version,
                "deviceModel": self.device_model
            },
            "attributes": {
                "channel": "mobileApp", 
                "origin": "standardProgram",
                "energyLabel": "0"
            },
            "ancillaryParameters": {
                "programFamily": "[standard]",
                "remoteActionable": "1", 
                "remoteVisible": "1"
            },
            "parameters": parameters,
            "applianceType": appliance.get("applianceTypeName", "WM")
        }
        
        try:
            url = f"{self.api_url}/commands/v1/send"
            async with self.session.post(url, headers=self.api_headers, json=command) as resp:
                data = await resp.json()
                
                if data["payload"]["resultCode"] == "0":
                    logger.info("✅ Commande envoyée avec succès!")
                    return True
                else:
                    logger.error(f"❌ Commande rejetée: {data}")
                    return False
        except Exception as e:
            logger.error(f"❌ Erreur envoi commande: {e}")
            return False

    async def pause_program(self, mac_address):
        """Met en pause le programme en cours"""
        appliance = self.find_appliance_by_mac(mac_address)
        if not appliance:
            logger.error(f"❌ Appareil non trouvé pour MAC: {mac_address}")
            return False
        
        print(f"\n⏸️ PAUSE DU PROGRAMME")
        print("="*40)
        logger.info(f"⏸️ Pause du programme sur {appliance['nickName']}")
        
        # Paramètres pour pause - CONFORME À LA SPEC
        pause_parameters = {
            "pause": "1"
        }
        
        result = await self.send_command(appliance, "startProgram", pause_parameters)
        
        if result:
            print("⏸️ PROGRAMME MIS EN PAUSE")
        else:
            print("❌ ÉCHEC DE LA PAUSE")
        
        return result

    async def resume_program(self, mac_address):
        """Reprend le programme en pause"""
        appliance = self.find_appliance_by_mac(mac_address)
        if not appliance:
            logger.error(f"❌ Appareil non trouvé pour MAC: {mac_address}")
            return False
        
        print(f"\n▶️ REPRISE DU PROGRAMME")
        print("="*40)
        logger.info(f"▶️ Reprise du programme sur {appliance['nickName']}")
        
        # Paramètres pour reprise - CONFORME À LA SPEC
        resume_parameters = {
            "pause": "0"
        }
        
        result = await self.send_command(appliance, "startProgram", resume_parameters)
        
        if result:
            print("▶️ PROGRAMME REPRIS")
        else:
            print("❌ ÉCHEC DE LA REPRISE")
        
        return result

    async def stop_program(self, mac_address, stop_machine=False):
        """Arrête le programme en cours
        
        Args:
            mac_address: Adresse MAC de l'appareil
            stop_machine: Si True, arrête complètement la machine (onOffStatus=0)
                         Si False, arrête seulement le programme (onOffStatus=1)
        """
        appliance = self.find_appliance_by_mac(mac_address)
        if not appliance:
            logger.error(f"❌ Appareil non trouvé pour MAC: {mac_address}")
            return False
        
        print(f"\n🛑 ARRÊT DU PROGRAMME")
        print("="*40)
        
        if stop_machine:
            logger.info(f"🛑 Arrêt complet de la machine {appliance['nickName']}")
            # Paramètres pour arrêt complet de la machine - CONFORME À LA SPEC
            stop_parameters = {
                "machMode": "3",
                "onOffStatus": "0"
            }
        else:
            logger.info(f"🛑 Arrêt du programme sur {appliance['nickName']}")
            # Paramètres pour arrêt du programme seulement - CONFORME À LA SPEC
            stop_parameters = {
                "machMode": "3", 
                "onOffStatus": "1"
            }
        
        result = await self.send_command(appliance, "startProgram", stop_parameters)
        
        if result:
            if stop_machine:
                print("🛑 MACHINE ARRÊTÉE COMPLÈTEMENT")
            else:
                print("🛑 PROGRAMME ARRÊTÉ")
        else:
            print("❌ ÉCHEC DE L'ARRÊT")
        
        return result

    async def stop_machine(self, mac_address):
        """Arrête complètement la machine (raccourci)"""
        return await self.stop_program(mac_address, stop_machine=True)

    async def launch_program(self, program_name, mac_address, custom_params_string=None):
        """Lance un programme sur un appareil spécifique"""
        # Chargement du JSON du programme
        program_data = self.load_program_json(mac_address, program_name)
        if not program_data:
            return False
        
        device_info = program_data['device_info']
        program_info = program_data['program_info']
        
        print(f"\n🧺 LANCEMENT DU PROGRAMME: {program_info['display_name']}")
        print("="*60)
        
        # Recherche de l'appareil
        appliance = self.find_appliance_by_mac(mac_address)
        if not appliance:
            logger.error(f"❌ Appareil non trouvé pour MAC: {mac_address}")
            return False
        
        logger.info(f"🏠 Appareil: {device_info['name']}")
        logger.info(f"📍 MAC: {mac_address}")
        logger.info(f"🎯 Programme: {program_info['display_name']}")
        
        # Paramètres par défaut depuis le JSON
        final_parameters = program_info["default_parameters"].copy()
        
        # Application des paramètres personnalisés
        custom_params = self.parse_custom_parameters(custom_params_string)
        if custom_params:
            logger.info(f"🎛️ Paramètres personnalisés: {custom_params}")
            
            # Validation des paramètres personnalisés
            for param_name, param_value in custom_params.items():
                if param_name in program_info["parameters"]:
                    param_info = program_info["parameters"][param_name]
                    
                    # Validation selon le type
                    if param_info["type"] == "enum":
                        if param_value not in [str(v) for v in param_info["values"]]:
                            logger.warning(f"⚠️ Valeur {param_value} non valide pour {param_name}. Valeurs autorisées: {param_info['values']}")
                            continue
                    elif param_info["type"] == "range":
                        try:
                            val = float(param_value)
                            if not (float(param_info["min"]) <= val <= float(param_info["max"])):
                                logger.warning(f"⚠️ Valeur {param_value} hors limites pour {param_name}. Limites: [{param_info['min']}-{param_info['max']}]")
                                continue
                        except ValueError:
                            logger.warning(f"⚠️ Valeur {param_value} non numérique pour {param_name}")
                            continue
                    
                    final_parameters[param_name] = param_value
                else:
                    logger.warning(f"⚠️ Paramètre {param_name} non reconnu pour ce programme")
        
        # Affichage des paramètres principaux
        logger.info(f"📊 Paramètres finaux:")
        key_params = ["temp", "spinSpeed", "mainWashTime", "rinseIterations", "delayTime"]
        for param in key_params:
            if param in final_parameters:
                value = final_parameters[param]
                logger.info(f"   • {param}: {value}")
        
        # Résumé du lancement
        print(f"\n🚀 LANCEMENT DU PROGRAMME: {program_info['display_name']}")
        
        key_display = {
            "temp": ("Température", "°C"),
            "spinSpeed": ("Essorage", "rpm"), 
            "mainWashTime": ("Durée lavage", "min"),
            "rinseIterations": ("Rinçages", "")
        }
        
        for param, (label, unit) in key_display.items():
            if param in final_parameters:
                value = final_parameters[param]
                print(f"   {label}: {value}{unit}")
        
        # Envoi direct de la commande
        logger.info("🚀 Envoi de la commande de démarrage...")
        result = await self.send_command(appliance, "startProgram", final_parameters)
        
        if result:
            print("\n🎉 PROGRAMME LANCÉ AVEC SUCCÈS!")
            print("   Votre appareil devrait démarrer dans quelques secondes")
            print("   Vérifiez l'écran de votre appareil pour confirmation")
        else:
            print("\n❌ ÉCHEC DU LANCEMENT")
            print("   Vérifiez que votre appareil est allumé et connecté")
        
        return result


async def main():
    """Fonction principale"""
    if len(sys.argv) < 4:
        print("🧺 LANCEUR RAPIDE hOn - VERSION MULTI-APPAREILS")
        print("="*60)
        print("Usage: python3 hon_launcher.py <email> <password> <commande> [mac] [params]")
        print("\nCommandes:")
        print("  devices                     - Liste tous les appareils disponibles")
        print("  list <mac>                  - Liste les programmes d'un appareil")
        print("  <program_name> <mac>        - Lance le programme sur l'appareil")
        print("  pause <mac>                 - Met en pause le programme en cours")
        print("  resume <mac>                - Reprend le programme en pause")
        print("  stop <mac>                  - Arrête le programme en cours")
        print("  stopmachine <mac>           - Arrête complètement la machine")
        print("\nExemples:")
        print("  python3 hon_launcher.py email@domain.com password123 devices")
        print("  python3 hon_launcher.py email@domain.com password123 list AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 cottons AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 pause AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 resume AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 stop AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 stopmachine AA:BB:CC:DD:EE:FF")
        print("  python3 hon_launcher.py email@domain.com password123 cottons AA:BB:CC:DD:EE:FF temp=30,spinSpeed=1200")
        print(f"\n💡 Les fichiers JSON doivent être présents dans: {DATA_DIR}/")
        print("    Format: MAC_programme.json (ex: AA-BB-CC-DD-EE-FF_cottons.json)")
        sys.exit(1)
    
    email = sys.argv[1]
    password = sys.argv[2]
    command = sys.argv[3]
    
    try:
        launcher = HonLauncher(email, password)
        
        if command.lower() == "devices":
            # Liste des appareils (pas besoin de connexion API)
            success = launcher.list_devices()
            return 0 if success else 1
            
        elif command.lower() == "list":
            # Liste des programmes d'un appareil (pas besoin de connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour la commande 'list'")
                return 1
            mac_address = sys.argv[4]
            success = launcher.list_programs_for_device(mac_address)
            return 0 if success else 1
            
        elif command.lower() == "pause":
            # Pause du programme (nécessite connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour la commande 'pause'")
                return 1
            mac_address = sys.argv[4]
            
            async with launcher:
                if not await launcher.authorize():
                    logger.error("❌ Échec de l'autorisation")
                    return 1
                
                success = await launcher.pause_program(mac_address)
                return 0 if success else 1
                
        elif command.lower() == "resume":
            # Reprise du programme (nécessite connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour la commande 'resume'")
                return 1
            mac_address = sys.argv[4]
            
            async with launcher:
                if not await launcher.authorize():
                    logger.error("❌ Échec de l'autorisation")
                    return 1
                
                success = await launcher.resume_program(mac_address)
                return 0 if success else 1
                
        elif command.lower() == "stop":
            # Arrêt du programme (nécessite connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour la commande 'stop'")
                return 1
            mac_address = sys.argv[4]
            
            async with launcher:
                if not await launcher.authorize():
                    logger.error("❌ Échec de l'autorisation")
                    return 1
                
                success = await launcher.stop_program(mac_address, stop_machine=False)
                return 0 if success else 1
                
        elif command.lower() == "stopmachine":
            # Arrêt complet de la machine (nécessite connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour la commande 'stopmachine'")
                return 1
            mac_address = sys.argv[4]
            
            async with launcher:
                if not await launcher.authorize():
                    logger.error("❌ Échec de l'autorisation")
                    return 1
                
                success = await launcher.stop_program(mac_address, stop_machine=True)
                return 0 if success else 1
            
        else:
            # Lancement d'un programme (nécessite connexion API)
            if len(sys.argv) < 5:
                logger.error("❌ Adresse MAC requise pour lancer un programme")
                return 1
                
            program_name = command
            mac_address = sys.argv[4]
            custom_params = sys.argv[5] if len(sys.argv) > 5 else None
            
            async with launcher:
                if not await launcher.authorize():
                    logger.error("❌ Échec de l'autorisation")
                    return 1
                
                success = await launcher.launch_program(program_name, mac_address, custom_params)
                return 0 if success else 1
            
    except Exception as e:
        logger.error(f"❌ Erreur fatale: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))