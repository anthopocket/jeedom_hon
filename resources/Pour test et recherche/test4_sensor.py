import asyncio
import aiohttp
import logging
import json
import os
from datetime import datetime, timedelta, timezone
from enum import IntEnum

# Configuration du logger
logging.basicConfig(level=logging.DEBUG)
_LOGGER = logging.getLogger(__name__)

# Constantes
API_URL = "https://api-iot.he.services"
ID_TOKEN = "eyJraWQiOiIyNTYiLCJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdF9oYXNoIjoiWTJlWWlnX1JldjRoRHJjUVpNVy0zZyIsInN1YiI6Imh0dHBzOi8vbG9naW4uc2FsZXNmb3JjZS5jb20vaWQvMDBEVTAwMDAwMDBMa2NxTUFDLzAwNTY4MDAwMDA2RzYwQkFBUyIsInpvbmVpbmZvIjoiRXVyb3BlL1BhcmlzIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsImFkZHJlc3MiOnt9LCJwcm9maWxlIjoiaHR0cHM6Ly9oYWllcmV1cm9wZS5teS5zYWxlc2ZvcmNlLmNvbS8wMDU2ODAwMDAwNkc2MEJBQVMiLCJpc3MiOiJodHRwczovL2FjY291bnQyLmhvbi1zbWFydGhvbWUuY29tL1NtYXJ0SG9tZSIsInByZWZlcnJlZF91c2VybmFtZSI6ImFudGhvbnkucG9uZGVwZXlyZUBnbWFpbC5jb20iLCJnaXZlbl9uYW1lIjoiQW50aG9ueSIsImxvY2FsZSI6ImVuX0dCIiwibm9uY2UiOiI4MmU5ZjRkMS0xNDBlLTQ4NzItOWZhZC0xNWUyNWZiZjJiN2MiLCJwaWN0dXJlIjoiaHR0cHM6Ly9hY2NvdW50Mi5ob24tc21hcnRob21lLmNvbS9pbWcvdXNlcnByb2ZpbGUvZGVmYXVsdF9wcm9maWxlXzIwMF92Mi5wbmciLCJjdXN0b21fYXR0cmlidXRlcyI6eyJQcml2YWN5VXBkYXRlZCI6InRydWUiLCJQZXJzb25Db250YWN0SWQiOiIwMDM2ODAwMDAwWGRpSVZBQVoiLCJKV1QiOiIiLCJFeHRlcm5hbFNvdXJjZSI6IklvVCBNb2JpbGUgQXBwIiwiRXh0ZXJuYWxTdWJTb3VyY2UiOiJoT24iLCJQZXJzb25BY2NvdW50SWQiOiIwMDE2ODAwMDAwZFIyTGpBQUsiLCJDb3VudHJ5IjoiRlIiLCJVc2VyTGFuZ3VhZ2UiOiJlbl9HQiJ9LCJhdWQiOiIzTVZHOVFEeDhJWDhuUDVUMkhhOG9mdmxtakxabDVMX2d2ZmJUOS5ISnZwSEdLb0FTX2RjTU44TFlwVFNZZVZGQ3JhVW5WLjJBZzFLaTdtNHpuVk82LG15bW9iaWxlYXBwOi8vY2FsbGJhY2ssY2FuZHk6Ly9tb2JpbGVzZGsvZGV0ZWN0L29hdXRoL2RvbmUsaG9vdmVyOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLHJvc2llcmVzOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLGhvbjovL21vYmlsZXNkay9kZXRlY3Qvb2F1dGgvZG9uZSxodHRwczovL2FwcC5nZXRwb3N0bWFuLmNvbS9vYXV0aDIvY2FsbGJhY2ssIiwidXBkYXRlZF9hdCI6IjIwMjUtMDctMTRUMTg6MDA6MjhaIiwibmlja25hbWUiOiJVc2VyMTcwMDMwOTc4MTIyNjY2MTE5MTMiLCJuYW1lIjoiQW50aG9ueSBQb25kZXBleXJlIiwicGhvbmVfbnVtYmVyIjpudWxsLCJleHAiOjE3NTQ2OTczMjYsImlhdCI6MTc1NDY2ODUyNiwiZmFtaWx5X25hbWUiOiJQb25kZXBleXJlIiwiZW1haWwiOiJhbnRob255LnBvbmRlcGV5cmVAZ21haWwuY29tIn0.ZDnongv0ThOtpM8E5RrYRcNTcWEUSDru3qvMrs0v404dEEfx5KrT4wTA9ma1nVfIrHyyl8KK_FUk7R7GJUhQO7I3iiVo_2vJb3H9SWRePwm5o9MxLtvD_MindNAi6ST3pT6BhxdJHv4_xd4sNZbwjkzfvZk284NHxK3hJeHGWqrHFGvihQ9P9k_Wm5bj3blm1L4AWdXOZ9_Su7ALPM1OsK3oYxp7o_fUjFZaN2AJJjD7m6rI_DK5KtMNoMa1xNLI0GXfqDy1lTXpnZefT3sHtPUF3PUnUT-RPlirfDp4yAK-9nA_IYiLczZss2ted4C8M7ohlqv9FEhZ4KE_zJJ5Evsh3vGzcuzQe2HnQBLMbCQZrqQ4BUFH-2WaNtPEoLHYyKza1bNHMrKBuhjWr_YMokgUkKwne0z9OxmhpThgC6lIl9xk8FvffhU4Zo3hw-g4v2AzYhuPaKZWdN_xP3r5DZAz3Sj7l72SLQ0gfp9UeuUpI0cp5Fxz6XP1fnLIx3YCRIyB0FvtEbiFWwG1XgodW5v2TGCDHVxF_YScpTFmT9T6g5JYdNTtGkM4cpitkyjKPluq6qoNW4BIbN78ZFs5oTziX3rJx0Jgw9220KYK-9lTQLHGC3PVSSe2eQZ77DyYS5AXhKady0hkCp4vhJMkL8tqRR51amo4s_3ovoR3LWE"
COGNITO_TOKEN = "eyJraWQiOiJldS13ZXN0LTEtOCIsInR5cCI6IkpXUyIsImFsZyI6IlJTNTEyIn0.eyJzdWIiOiJldS13ZXN0LTE6MDQ3NDhkN2EtMjAyYi00ZDM0LTk2YjktYTM2NjlhOTQ1OTRmIiwiYXVkIjoiZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImFtciI6WyJhdXRoZW50aWNhdGVkIiwibG9naW4uaGFpZXIuYXBwIiwibG9naW4uaGFpZXIuYXBwOmV1LXdlc3QtMTpkOGNjYTFkYi05ZmUzLTQ1ZGYtYmZjOC01MmM4ODQwNThlNjI6MDAxNjgwMDAwMGRSMkxqQUFLIl0sImlzcyI6Imh0dHBzOi8vY29nbml0by1pZGVudGl0eS5hbWF6b25hd3MuY29tIiwiaHR0cHM6Ly9jb2duaXRvLWlkZW50aXR5LmFtYXpvbmF3cy5jb20vaWRlbnRpdHktcG9vbC1hcm4iOiJhcm46YXdzOmNvZ25pdG8taWRlbnRpdHk6ZXUtd2VzdC0xOjgyODc5NDkxMzc4NjppZGVudGl0eXBvb2wvZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImV4cCI6MTc1NDY4MjkyNywiaWF0IjoxNzU0NjY4NTI3fQ.Ib2ZDLKVZnCWdbuGjNIz3ihZ56yjPoEQ_IW9OPEfnKmyP7box6qhJtdokxELXujlFrjCVjvwJXW_NBNgLUtRxtfeGKKS5bJ1LZMfoTxINSS11H3q1RZUGFCW3pQnFNivK_53n7hhWkf4n58-p4sN_ADzKbDwvuVHdwTQDSA6G-t60Ake8Ojip9K05vI_zBVZfH7MygNj5S50cGhHF1WeD36huTecBYPpvbDs0_JshdBpGLE6IrR7lW5jM9a_0qOezhIh2GmKRqAklS0S5xJMHRZydzWEmslMTk3EkMvwhdvV6KU4l8qoEleIDFBMkQ4pF6fy_74vx_CsjmcyjWQXhQ"
MAC_ADDRESS = "54-43-b2-e9-6d-78"
APPLIANCE_TYPE = "WM"
OS = "android"
APP_VERSION = "2.0.10"

# Types d'appareils supportés
class ApplianceType(IntEnum):
    WASHING_MACHINE = 1
    TUMBLE_DRYER = 2
    WASH_DRYER = 3

# Mapping des types d'appareils
APPLIANCE_MAPPING = {
    "WM": ApplianceType.WASHING_MACHINE,
    "TD": ApplianceType.TUMBLE_DRYER,
    "WD": ApplianceType.WASH_DRYER
}

class HonDevice:
    """Classe pour simuler l'objet device du code HA"""
    def __init__(self, data):
        self.data = data
        
    def has(self, key):
        return key in self.data
        
    def get(self, key, default=None):
        return self.data.get(key, default)
        
    def getInt(self, key, default=0):
        try:
            return int(self.data.get(key, default))
        except (ValueError, TypeError):
            return default
            
    def getFloat(self, key, default=0.0):
        try:
            return float(self.data.get(key, default))
        except (ValueError, TypeError):
            return default

class HonConnection:
    def __init__(self):
        self._header = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36"
        }
        self._id_token = ID_TOKEN
        self._cognitoToken = COGNITO_TOKEN
        self._session = None

    @property
    def _headers(self):
        return {
            "Content-Type": "application/json",
            "cognito-token": self._cognitoToken,
            "id-token": self._id_token,
        }

    async def refresh_cognito_token(self):
        if self._session is None:
            self._session = aiohttp.ClientSession(headers=self._header, connector=aiohttp.TCPConnector(ssl=False))
        post_headers = {"id-token": self._id_token}
        data = {
            "appVersion": APP_VERSION,
            "mobileId": "8f94c74f2d70fed4",
            "os": OS,
            "osVersion": "31",
            "deviceModel": "exynos9820"
        }
        url = f"{API_URL}/auth/v1/login"
        try:
            async with self._session.post(url, headers=post_headers, json=data) as resp:
                if resp.status != 200:
                    _LOGGER.error(f"Erreur HTTP {resp.status} pour {url}")
                    return None
                json_data = await resp.json()
                new_token = json_data.get("cognitoUser", {}).get("Token")
                if new_token:
                    self._cognitoToken = new_token
                    _LOGGER.debug(f"Nouveau cognitoToken: {new_token}")
                    return new_token
                else:
                    _LOGGER.error("Aucun cognitoToken dans la réponse")
                    return None
        except Exception as e:
            _LOGGER.error(f"Erreur lors de la requête: {str(e)}")
            return None

    async def async_get_context(self, mac_address, appliance_type):
        if self._session is None:
            self._session = aiohttp.ClientSession(headers=self._header, connector=aiohttp.TCPConnector(ssl=False))
        params = {
            "macAddress": mac_address,
            "applianceType": appliance_type,
            "category": "CYCLE"
        }
        url = f"{API_URL}/commands/v1/context"
        try:
            async with self._session.get(url, params=params, headers=self._headers) as response:
                if response.status != 200:
                    _LOGGER.error(f"Erreur HTTP {response.status} pour {url}")
                    return {}
                data = await response.json()
                _LOGGER.debug(f"Contexte pour mac[{mac_address}] type [{appliance_type}]: {data}")
                return data.get("payload", {})
        except Exception as e:
            _LOGGER.error(f"Erreur lors de la requête: {str(e)}")
            return {}

    async def async_close(self):
        if self._session is not None:
            await self._session.close()

class WashingMachineData:
    """Classe pour extraire les données spécifiques aux machines à laver et sèche-linge"""
    
    def __init__(self, device, appliance_type):
        self.device = device
        self.appliance_type = appliance_type
        self.sensors = {}
        
    def extract_all_sensors(self):
        """Extrait tous les capteurs supportés pour les machines à laver et sèche-linge"""
        
        # Mode de la machine
        if self.device.has("machMode"):
            self.sensors["mode"] = self._get_mode()
            
        # Temps restant et horaires
        if self.device.has("remainingTimeMM"):
            self.sensors["remaining_time"] = self._get_remaining_time()
            self.sensors["start_time"] = self._get_start_time()
            self.sensors["end_time"] = self._get_end_time()
            
        # Programme
        if self.device.has("prCode"):
            self.sensors["program_code"] = self.device.get("prCode")
            
        if self.device.has("prPhase"):
            self.sensors["program_phase"] = self.device.get("prPhase")
            
        if self.device.has("prTime"):
            self.sensors["program_duration"] = self.device.getInt("prTime")
            
        # Niveau de séchage (pour sèche-linge)
        if self.device.has("dryLevel"):
            self.sensors["dry_level"] = self.device.get("dryLevel")
            
        # Statistiques totales
        if self.device.has("totalWashCycle"):
            self.sensors["total_wash_cycle"] = self.device.getInt("totalWashCycle") - 1
            
        if self.device.has("totalWaterUsed"):
            self.sensors["total_water_used"] = self.device.getFloat("totalWaterUsed") / 100.0
            
        if self.device.has("totalElectricityUsed") and self.device.getFloat("totalElectricityUsed") > 0:
            self.sensors["total_electricity_used"] = self.device.getFloat("totalElectricityUsed")
            
        # Consommation moyenne d'eau
        if self.device.has("totalWaterUsed") and self.device.has("totalWashCycle"):
            cycles = self.device.getInt("totalWashCycle") - 1
            if cycles > 0:
                self.sensors["mean_water_consumption"] = round(
                    self.device.getFloat("totalWaterUsed") / cycles / 100.0, 2
                )
                
        # Poids estimé
        if self.device.has("actualWeight"):
            self.sensors["estimated_weight"] = self.device.getFloat("actualWeight")
            
        # Consommation actuelle
        if self.device.has("currentWaterUsed"):
            self.sensors["current_water_used"] = self.device.getFloat("currentWaterUsed") / 100.0
            
        if self.device.has("currentElectricityUsed"):
            self.sensors["current_electricity_used"] = self.device.getFloat("currentElectricityUsed") / 100.0
            
        # Vitesse d'essorage
        if self.device.has("spinSpeed"):
            self.sensors["spin_speed"] = self._get_spin_speed()
            
        # Erreurs
        if self.device.has("errors"):
            self.sensors["error"] = self.device.get("errors")
            
        # Températures
        if self.device.has("temp"):
            self.sensors["temperature"] = self.device.getInt("temp")
            
        if self.device.has("tempSel"):
            self.sensors["selected_temperature"] = self.device.getInt("tempSel")
            
        return self.sensors
    
    def _get_mode(self):
        """Retourne le mode de la machine"""
        mode = self.device.get("machMode")
        mode_names = {
            "0": "Off",
            "1": "Ready", 
            "2": "Running",
            "3": "Pause",
            "4": "Scheduled",
            "5": "Error",
            "6": "Finished",
            "7": "Test"
        }
        return {
            "value": mode,
            "name": mode_names.get(str(mode), f"Unknown ({mode})")
        }
    
    def _get_remaining_time(self):
        """Calcule le temps restant selon la logique du code HA"""
        delay = 0
        remaining_time = self.device.getInt("remainingTimeMM")
        
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
            
        mach_mode = 0
        if self.device.has("machMode"):
            mach_mode = self.device.getInt("machMode")
            
        # Logique pour machine à laver
        if self.appliance_type == ApplianceType.WASHING_MACHINE:
            if mach_mode in (1, 6):  # Ready ou Finished
                return 0
            else:
                return remaining_time
                
        # Logique pour lave-linge séchant
        elif self.appliance_type == ApplianceType.WASH_DRYER:
            time = delay
            if mach_mode != 7:  # Pas en mode test
                time = delay + remaining_time
            return time
            
        # Autres appareils
        else:
            return delay + remaining_time
    
    def _get_start_time(self):
        """Calcule l'heure de début"""
        delay = 0
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
            
        is_on = self._is_machine_on()
        
        if delay == 0:
            if is_on:
                return datetime.now(timezone.utc).replace(second=0).isoformat()
            else:
                return None
        else:
            return (datetime.now(timezone.utc).replace(second=0) + 
                   timedelta(minutes=delay)).isoformat()
    
    def _get_end_time(self):
        """Calcule l'heure de fin"""
        delay = 0
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
            
        remaining = self.device.getInt("remainingTimeMM")
        is_on = self._is_machine_on()
        
        if remaining == 0 or not is_on:
            return None
            
        return (datetime.now(timezone.utc).replace(second=0) + 
               timedelta(minutes=delay + remaining)).isoformat()
    
    def _get_spin_speed(self):
        """Retourne la vitesse d'essorage"""
        spin_speed = self.device.getInt("spinSpeed")
        
        # Pour les machines à laver, vitesse = 0 si en mode Ready ou Finished
        if self.appliance_type == ApplianceType.WASHING_MACHINE:
            mach_mode = self.device.get("machMode")
            if mach_mode in ("1", "6"):  # Ready ou Finished
                return 0
                
        return spin_speed
    
    def _is_machine_on(self):
        """Détermine si la machine est allumée"""
        if self.device.has("onOffStatus"):
            return self.device.get("onOffStatus") == "1"
        else:
            # Fallback sur le statut de connexion
            return self.device.get("attributes.lastConnEvent.category") == "CONNECTED"

async def main():
    """Fonction principale pour Jeedom"""
    hon = HonConnection()
    
    try:
        # Actualisation du token
        if not await hon.refresh_cognito_token():
            print("❌ Échec de la récupération du cognitoToken.")
            return {}

        # Récupération du contexte
        context = await hon.async_get_context(MAC_ADDRESS, APPLIANCE_TYPE)
        
        if not context or "shadow" not in context or "parameters" not in context["shadow"]:
            print("❌ Données non trouvées ou structure incorrecte.")
            return {}

        # Détermination du type d'appareil
        appliance_type = APPLIANCE_MAPPING.get(APPLIANCE_TYPE, ApplianceType.WASHING_MACHINE)
        
        # Vérification que c'est bien une machine à laver ou un sèche-linge
        if appliance_type not in [ApplianceType.WASHING_MACHINE, ApplianceType.TUMBLE_DRYER, ApplianceType.WASH_DRYER]:
            print(f"❌ Type d'appareil non supporté: {APPLIANCE_TYPE}")
            return {}

        print(f"\n📦 Extraction des données pour {APPLIANCE_TYPE} (Type: {appliance_type.name})...\n")

        # Création de l'objet device avec les paramètres
        device_data = {}
        parameters = context["shadow"]["parameters"]
        
        # Extraction de tous les paramètres avec parNewVal
        for key, param in parameters.items():
            if isinstance(param, dict) and "parNewVal" in param:
                device_data[key] = param.get("parNewVal")

        # Création du device et extraction des données
        device = HonDevice(device_data)
        washing_data = WashingMachineData(device, appliance_type)
        sensors = washing_data.extract_all_sensors()

        # Formatage de la sortie pour Jeedom
        jeedom_output = {
            "device_info": {
                "mac_address": MAC_ADDRESS,
                "appliance_type": APPLIANCE_TYPE,
                "appliance_name": appliance_type.name,
                "last_update": datetime.now(timezone.utc).isoformat()
            },
            "sensors": sensors
        }

        print(json.dumps(jeedom_output, indent=4, ensure_ascii=False))
        return jeedom_output

    except Exception as e:
        _LOGGER.error(f"Erreur dans main(): {str(e)}")
        return {}
    finally:
        await hon.async_close()

if __name__ == "__main__":
    result = asyncio.run(main())