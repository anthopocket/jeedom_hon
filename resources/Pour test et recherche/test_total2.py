import asyncio
import aiohttp
import logging
import json
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

# Configuration des sensors spécifiques par type d'appareil
WASHING_MACHINE_SENSORS = {
    "child_lock": {"key": "lockStatus", "type": "binary", "name": "Child lock"},
    "current_electricity_used": {"key": "currentElectricityUsed", "type": "numeric", "name": "Current electricity used", "unit": "kWh", "conversion": lambda x: float(x) / 100.0},
    "current_water_used": {"key": "currentWaterUsed", "type": "numeric", "name": "Current water used", "unit": "L", "conversion": lambda x: float(x) / 100.0},
    "door_lock": {"key": "doorLockStatus", "type": "binary", "name": "Door lock"},
    "door_status": {"key": "doorStatus", "type": "binary", "name": "Door status"},
    "dry_level": {"key": "dryLevel", "type": "numeric", "name": "Dry level"},
    "end_time": {"key": "calculated", "type": "calculated", "name": "End time"},
    "error": {"key": "errors", "type": "text", "name": "Error"},
    "estimated_weight": {"key": "actualWeight", "type": "numeric", "name": "Estimated weight", "unit": "kg"},
    "mean_water_consumption": {"key": "calculated", "type": "calculated", "name": "Mean water consumption", "unit": "L/cycle"},
    "mode": {"key": "machMode", "type": "enum", "name": "Mode"},
    "program_code": {"key": "prCode", "type": "text", "name": "Program code"},
    "program_name": {"key": "calculated", "type": "calculated", "name": "Program name"},
    "program_phase": {"key": "prPhase", "type": "numeric", "name": "Program phase"},
    "remaining_time": {"key": "calculated", "type": "calculated", "name": "Remaining time", "unit": "min"},
    "remote_control": {"key": "remoteCtrValid", "type": "binary", "name": "Remote control"},
    "spin_speed": {"key": "spinSpeed", "type": "numeric", "name": "Spin speed", "unit": "rpm"},
    "start_time": {"key": "calculated", "type": "calculated", "name": "Start time"},
    "status": {"key": "calculated", "type": "calculated", "name": "Status"},
    "temperature": {"key": "temp", "type": "numeric", "name": "Temperature", "unit": "°C"},
    "total_electricity_used": {"key": "totalElectricityUsed", "type": "numeric", "name": "Total electricity used", "unit": "kWh"},
    "total_wash_cycle": {"key": "totalWashCycle", "type": "numeric", "name": "Total wash cycle", "conversion": lambda x: int(x) - 1},
    "total_water_used": {"key": "totalWaterUsed", "type": "numeric", "name": "Total water used", "unit": "L", "conversion": lambda x: float(x) / 100.0}
}

TUMBLE_DRYER_SENSORS = {
    "child_lock": {"key": "lockStatus", "type": "binary", "name": "Child lock"},
    "door_status": {"key": "doorStatus", "type": "binary", "name": "Door status"},
    "dry_level": {"key": "dryLevel", "type": "numeric", "name": "Dry level"},
    "end_time": {"key": "calculated", "type": "calculated", "name": "End time"},
    "error": {"key": "errors", "type": "text", "name": "Error"},
    "mode": {"key": "machMode", "type": "enum", "name": "Mode"},
    "program_code": {"key": "prCode", "type": "text", "name": "Program code"},
    "program_phase": {"key": "prPhase", "type": "numeric", "name": "Program phase"},
    "remaining_time": {"key": "calculated", "type": "calculated", "name": "Remaining time", "unit": "min"},
    "remote_control": {"key": "remoteCtrValid", "type": "binary", "name": "Remote control"},
    "start_time": {"key": "calculated", "type": "calculated", "name": "Start time"},
    "status": {"key": "calculated", "type": "calculated", "name": "Status"},
    "sterilization_status": {"key": "sterilizationStatus", "type": "binary", "name": "Sterilization status"},
    "tumble_dryer_program_name": {"key": "calculated", "type": "calculated", "name": "Tumble Dryer Program name"}
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

class HonSensorExtractor:
    """Classe pour extraire les sensors selon le type d'appareil"""
    
    def __init__(self, device, appliance_type, parameters, context):
        self.device = device
        self.appliance_type = appliance_type
        self.parameters = parameters
        self.context = context
        
        # Sélection des sensors selon le type d'appareil
        if appliance_type == ApplianceType.WASHING_MACHINE:
            self.sensor_config = WASHING_MACHINE_SENSORS
        elif appliance_type == ApplianceType.TUMBLE_DRYER:
            self.sensor_config = TUMBLE_DRYER_SENSORS
        else:
            self.sensor_config = WASHING_MACHINE_SENSORS  # Fallback
    
    def extract_sensors(self):
        """Extrait tous les sensors configurés pour l'appareil"""
        sensors = {}
        
        for sensor_name, config in self.sensor_config.items():
            sensor_value = self._extract_sensor(sensor_name, config)
            if sensor_value is not None:
                sensors[sensor_name] = sensor_value
                
        return sensors
    
    def _extract_sensor(self, sensor_name, config):
        """Extrait un sensor spécifique"""
        sensor_type = config["type"]
        key = config["key"]
        
        if sensor_type == "binary":
            return self._extract_binary_sensor(key, config)
        elif sensor_type == "numeric":
            return self._extract_numeric_sensor(key, config)
        elif sensor_type == "text":
            return self._extract_text_sensor(key, config)
        elif sensor_type == "enum":
            return self._extract_enum_sensor(key, config)
        elif sensor_type == "calculated":
            return self._extract_calculated_sensor(sensor_name, config)
        
        return None
    
    def _extract_binary_sensor(self, key, config):
        """Extrait un sensor binaire"""
        if not self.device.has(key):
            return None
            
        value = self.device.get(key) == "1"
        last_update = None
        
        if key in self.parameters:
            last_update = self.parameters[key].get("lastUpdate")
        
        # États spécifiques selon le sensor
        if key in ["doorLockStatus", "lockStatus"]:
            state_on = "Verrouillé"
            state_off = "Déverrouillé"
        elif key == "doorStatus":
            state_on = "Ouvert"
            state_off = "Fermé"
        elif key == "remoteCtrValid":
            state_on = "Activé"
            state_off = "Désactivé"
        else:
            state_on = "Activé"
            state_off = "Désactivé"
        
        return {
            "value": value,
            "name": config["name"],
            "state_on": state_on,
            "state_off": state_off,
            "last_update": last_update
        }
    
    def _extract_numeric_sensor(self, key, config):
        """Extrait un sensor numérique"""
        if not self.device.has(key):
            return None
            
        raw_value = self.device.get(key)
        
        # Conversion si spécifiée
        if "conversion" in config:
            value = config["conversion"](raw_value)
        else:
            try:
                value = float(raw_value)
            except (ValueError, TypeError):
                return None
        
        last_update = None
        if key in self.parameters:
            last_update = self.parameters[key].get("lastUpdate")
        
        result = {
            "value": value,
            "name": config["name"],
            "last_update": last_update
        }
        
        if "unit" in config:
            result["unit"] = config["unit"]
            
        return result
    
    def _extract_text_sensor(self, key, config):
        """Extrait un sensor texte"""
        if not self.device.has(key):
            return None
            
        value = self.device.get(key)
        last_update = None
        
        if key in self.parameters:
            last_update = self.parameters[key].get("lastUpdate")
        
        return {
            "value": value,
            "name": config["name"],
            "last_update": last_update
        }
    
    def _extract_enum_sensor(self, key, config):
        """Extrait un sensor énuméré (mode machine)"""
        if not self.device.has(key):
            return None
            
        mode = self.device.get(key)
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
        
        last_update = None
        if key in self.parameters:
            last_update = self.parameters[key].get("lastUpdate")
        
        return {
            "value": mode,
            "name": config["name"],
            "state_name": mode_names.get(str(mode), f"Unknown ({mode})"),
            "last_update": last_update
        }
    
    def _extract_calculated_sensor(self, sensor_name, config):
        """Extrait les sensors calculés"""
        if sensor_name == "remaining_time":
            return self._calculate_remaining_time(config)
        elif sensor_name == "start_time":
            return self._calculate_start_time(config)
        elif sensor_name == "end_time":
            return self._calculate_end_time(config)
        elif sensor_name == "mean_water_consumption":
            return self._calculate_mean_water_consumption(config)
        elif sensor_name == "status":
            return self._calculate_status(config)
        elif sensor_name == "program_name":
            return self._calculate_program_name(config)
        elif sensor_name == "tumble_dryer_program_name":
            return self._calculate_program_name(config)
        
        return None
    
    def _calculate_remaining_time(self, config):
        """Calcule le temps restant"""
        if not self.device.has("remainingTimeMM"):
            return None
            
        delay = 0
        remaining_time = self.device.getInt("remainingTimeMM")
        
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
            
        mach_mode = 0
        if self.device.has("machMode"):
            mach_mode = self.device.getInt("machMode")
        
        # Logique selon le type d'appareil
        if self.appliance_type == ApplianceType.WASHING_MACHINE:
            if mach_mode in (1, 6):  # Ready ou Finished
                final_time = 0
            else:
                final_time = remaining_time
        else:
            final_time = delay + remaining_time
        
        return {
            "value": final_time,
            "name": config["name"],
            "unit": config.get("unit", "min"),
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _calculate_start_time(self, config):
        """Calcule l'heure de début"""
        delay = 0
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
        
        is_on = self._is_machine_on()
        
        if delay == 0:
            if is_on:
                start_time = datetime.now(timezone.utc).replace(second=0).isoformat()
            else:
                start_time = None
        else:
            start_time = (datetime.now(timezone.utc).replace(second=0) + 
                         timedelta(minutes=delay)).isoformat()
        
        return {
            "value": start_time,
            "name": config["name"],
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _calculate_end_time(self, config):
        """Calcule l'heure de fin"""
        delay = 0
        if self.device.has("delayTime"):
            delay = self.device.getInt("delayTime")
        
        remaining = self.device.getInt("remainingTimeMM")
        is_on = self._is_machine_on()
        
        if remaining == 0 or not is_on:
            end_time = None
        else:
            end_time = (datetime.now(timezone.utc).replace(second=0) + 
                       timedelta(minutes=delay + remaining)).isoformat()
        
        return {
            "value": end_time,
            "name": config["name"],
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _calculate_mean_water_consumption(self, config):
        """Calcule la consommation moyenne d'eau"""
        if not (self.device.has("totalWaterUsed") and self.device.has("totalWashCycle")):
            return None
        
        cycles = self.device.getInt("totalWashCycle") - 1
        if cycles <= 0:
            return None
        
        mean_consumption = round(
            self.device.getFloat("totalWaterUsed") / cycles / 100.0, 2
        )
        
        return {
            "value": mean_consumption,
            "name": config["name"],
            "unit": config.get("unit", "L/cycle"),
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _calculate_status(self, config):
        """Calcule le statut global de l'appareil"""
        if self.device.has("onOffStatus"):
            is_connected = self.device.get("onOffStatus") == "1"
        else:
            is_connected = self.context.get("lastConnEvent", {}).get("category") == "CONNECTED"
        
        if not is_connected:
            status = "Disconnected"
        else:
            mode = self.device.get("machMode", "0")
            mode_status = {
                "0": "Off",
                "1": "Ready", 
                "2": "Running",
                "3": "Pause",
                "4": "Scheduled",
                "5": "Error",
                "6": "Finished",
                "7": "Test"
            }
            status = mode_status.get(mode, "Unknown")
        
        return {
            "value": status,
            "name": config["name"],
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _calculate_program_name(self, config):
        """Calcule le nom du programme (placeholder - nécessite mapping des codes programmes)"""
        program_code = self.device.get("prCode", "")
        
        # Mapping basique des codes programmes (à compléter selon vos besoins)
        program_names = {
            "113": "Cotton 30°C",
            "114": "Cotton 40°C",
            "115": "Cotton 60°C",
            # Ajouter d'autres mappings selon vos codes programmes
        }
        
        program_name = program_names.get(program_code, f"Program {program_code}")
        
        return {
            "value": program_name,
            "name": config["name"],
            "program_code": program_code,
            "last_update": datetime.now(timezone.utc).isoformat()
        }
    
    def _is_machine_on(self):
        """Détermine si la machine est allumée"""
        if self.device.has("onOffStatus"):
            return self.device.get("onOffStatus") == "1"
        else:
            return self.context.get("lastConnEvent", {}).get("category") == "CONNECTED"

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

async def main():
    """Fonction principale pour extraire uniquement les sensors spécifiés"""
    hon = HonConnection()
    
    try:
        print("🔄 Actualisation du cognitoToken...")
        if not await hon.refresh_cognito_token():
            print("❌ Échec de la récupération du cognitoToken.")
            return {}

        print("📡 Récupération du contexte HON...")
        context = await hon.async_get_context(MAC_ADDRESS, APPLIANCE_TYPE)
        
        if not context or "shadow" not in context or "parameters" not in context["shadow"]:
            print("❌ Données non trouvées ou structure incorrecte.")
            return {}

        parameters = context["shadow"]["parameters"]
        appliance_type = APPLIANCE_MAPPING.get(APPLIANCE_TYPE, ApplianceType.WASHING_MACHINE)
        
        print(f"📦 Extraction des sensors pour {appliance_type.name}...")
        
        # Création de l'objet device avec les paramètres
        device_data = {}
        for key, param in parameters.items():
            if isinstance(param, dict) and "parNewVal" in param:
                device_data[key] = param.get("parNewVal")

        # Création du device et extraction des sensors
        device = HonDevice(device_data)
        extractor = HonSensorExtractor(device, appliance_type, parameters, context)
        sensors = extractor.extract_sensors()

        # Structure finale du JSON
        result = {
            "device_info": {
                "name": f"hOn {appliance_type.name.replace('_', ' ').title()}",
                "mac_address": MAC_ADDRESS,
                "appliance_type": APPLIANCE_TYPE,
                "appliance_name": appliance_type.name.replace('_', ' ').title(),
                "last_update": datetime.now(timezone.utc).isoformat()
            },
            "sensors": sensors,
            "metadata": {
                "plugin_version": "1.0.0",
                "extraction_timestamp": datetime.now(timezone.utc).isoformat(),
                "total_sensors": len(sensors),
                "appliance_type": appliance_type.name
            }
        }

        print(f"\n✅ Extraction terminée avec succès !")
        print(f"📊 {len(sensors)} sensors extraits pour {appliance_type.name.replace('_', ' ')}")
        
        # Affichage des sensors trouvés
        print(f"\n🔍 Sensors disponibles:")
        for sensor_name, sensor_data in sensors.items():
            print(f"  - {sensor_data['name']}: {sensor_data.get('value', 'N/A')}")
        
        print(f"\n📄 JSON final:")
        print(json.dumps(result, indent=2, ensure_ascii=False))
        
        return result

    except Exception as e:
        _LOGGER.error(f"Erreur dans main(): {str(e)}")
        return {}
    finally:
        await hon.async_close()

if __name__ == "__main__":
    result = asyncio.run(main())