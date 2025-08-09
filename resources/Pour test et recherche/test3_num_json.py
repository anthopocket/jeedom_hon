import asyncio
import aiohttp
import logging
import json
import os
from datetime import datetime

# Configuration du logger
logging.basicConfig(level=logging.DEBUG)
_LOGGER = logging.getLogger(__name__)

# Constantes
API_URL = "https://api-iot.he.services"
ID_TOKEN = "eyJraWQiOiIyNTYiLCJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdF9oYXNoIjoidnRwaE91bmNqam9DZGV3aFE3NDZVZyIsInN1YiI6Imh0dHBzOi8vbG9naW4uc2FsZXNmb3JjZS5jb20vaWQvMDBEVTAwMDAwMDBMa2NxTUFDLzAwNTY4MDAwMDA2RzYwQkFBUyIsInpvbmVpbmZvIjoiRXVyb3BlL1BhcmlzIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsImFkZHJlc3MiOnt9LCJwcm9maWxlIjoiaHR0cHM6Ly9oYWllcmV1cm9wZS5teS5zYWxlc2ZvcmNlLmNvbS8wMDU2ODAwMDAwNkc2MEJBQVMiLCJpc3MiOiJodHRwczovL2FjY291bnQyLmhvbi1zbWFydGhvbWUuY29tL1NtYXJ0SG9tZSIsInByZWZlcnJlZF91c2VybmFtZSI6ImFudGhvbnkucG9uZGVwZXlyZUBnbWFpbC5jb20iLCJnaXZlbl9uYW1lIjoiQW50aG9ueSIsImxvY2FsZSI6ImVuX0dCIiwibm9uY2UiOiI4MmU5ZjRkMS0xNDBlLTQ4NzItOWZhZC0xNWUyNWZiZjJiN2MiLCJwaWN0dXJlIjoiaHR0cHM6Ly9hY2NvdW50Mi5ob24tc21hcnRob21lLmNvbS9pbWcvdXNlcnByb2ZpbGUvZGVmYXVsdF9wcm9maWxlXzIwMF92Mi5wbmciLCJjdXN0b21fYXR0cmlidXRlcyI6eyJQcml2YWN5VXBkYXRlZCI6InRydWUiLCJQZXJzb25Db250YWN0SWQiOiIwMDM2ODAwMDAwWGRpSVZBQVoiLCJKV1QiOiIiLCJFeHRlcm5hbFNvdXJjZSI6IklvVCBNb2JpbGUgQXBwIiwiRXh0ZXJuYWxTdWJTb3VyY2UiOiJoT24iLCJQZXJzb25BY2NvdW50SWQiOiIwMDE2ODAwMDAwZFIyTGpBQUsiLCJDb3VudHJ5IjoiRlIiLCJVc2VyTGFuZ3VhZ2UiOiJlbl9HQiJ9LCJhdWQiOiIzTVZHOVFEeDhJWDhuUDVUMkhhOG9mdmxtakxabDVMX2d2ZmJUOS5ISnZwSEdLb0FTX2RjTU44TFlwVFNZZVZGQ3JhVW5WLjJBZzFLaTdtNHpuVk82LG15bW9iaWxlYXBwOi8vY2FsbGJhY2ssY2FuZHk6Ly9tb2JpbGVzZGsvZGV0ZWN0L29hdXRoL2RvbmUsaG9vdmVyOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLHJvc2llcmVzOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLGhvbjovL21vYmlsZXNkay9kZXRlY3Qvb2F1dGgvZG9uZSxodHRwczovL2FwcC5nZXRwb3N0bWFuLmNvbS9vYXV0aDIvY2FsbGJhY2ssIiwidXBkYXRlZF9hdCI6IjIwMjUtMDctMTRUMTg6MDA6MjhaIiwibmlja25hbWUiOiJVc2VyMTcwMDMwOTc4MTIyNjY2MTE5MTMiLCJuYW1lIjoiQW50aG9ueSBQb25kZXBleXJlIiwicGhvbmVfbnVtYmVyIjpudWxsLCJleHAiOjE3NTQ2MjExOTAsImlhdCI6MTc1NDU5MjM5MCwiZmFtaWx5X25hbWUiOiJQb25kZXBleXJlIiwiZW1haWwiOiJhbnRob255LnBvbmRlcGV5cmVAZ21haWwuY29tIn0.lZ9HMDU41_vN5S5n9tpjJDRfQu4mVB20HbWzlrHBvvnfGmfNEBGBqDGvIf17rHf7qgDakvTYZL0l_rIG9ez0O_gXlWIhv8XlttfXx416kDaxwkABIin8eWvfMlwt9TSblL75PZgv0czlPz00BfAnZuG4wz8HssvheAXoBqARnWN2XDSHJpXr5Fy7sRVRG2HGEuFWWQrLTLE2aXWZYcToElcTqlMkr96E2VM6Efd1YW2Ts2UIl3E2O97fy3OCBZatNz8OLs9OJcdHBfssLNsvtUKwo0BzzfcnAWZSA7g4dYN0J186X4e_rQgrfdwiJoJNugLRoDNEOmGUSzfkq7tUrZ-0dyVmVgB64qQW-N4OgWcxIM-kIrA8SDsJp-t2eeQs8bH_JgIzv_fsf-WdylH5FY7sYQ_inxuMq9dbMVym5EEroqiXR7bIT8SJNvusgw0TBZwxu0_7dAVDQvmnbdgFPflsdWSBdHu6tnI1ouQAVBk0W_yHyZfzYKCDuZZ9gHuO12-VD5uXngNciPUl6xyZpPntiWSQ1OHTT0SWBcBTGEQvHc9m2m_UJPVW6IZF2mXQ1bFwaFMGu1LyFCA-wLP-N--JGTP5TRa6TrWtQpynASH4dO9awMchs4Mq_vxI91MjVRUYhOtmxoAVNSwAUBdgK3lmOEgiGNWMrGLFUXTIuNk"
COGNITO_TOKEN = "eyJraWQiOiJldS13ZXN0LTEtOCIsInR5cCI6IkpXUyIsImFsZyI6IlJTNTEyIn0.eyJzdWIiOiJldS13ZXN0LTE6MDQ3NDhkN2EtMjAyYi00ZDM0LTk2YjktYTM2NjlhOTQ1OTRmIiwiYXVkIjoiZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImFtciI6WyJhdXRoZW50aWNhdGVkIiwibG9naW4uaGFpZXIuYXBwIiwibG9naW4uaGFpZXIuYXBwOmV1LXdlc3QtMTpkOGNjYTFkYi05ZmUzLTQ1ZGYtYmZjOC01MmM4ODQwNThlNjI6MDAxNjgwMDAwMGRSMkxqQUFLIl0sImlzcyI6Imh0dHBzOi8vY29nbml0by1pZGVudGl0eS5hbWF6b25hd3MuY29tIiwiaHR0cHM6Ly9jb2duaXRvLWlkZW50aXR5LmFtYXpvbmF3cy5jb20vaWRlbnRpdHktcG9vbC1hcm4iOiJhcm46YXdzOmNvZ25pdG8taWRlbnRpdHk6ZXUtd2VzdC0xOjgyODc5NDkxMzc4NjppZGVudGl0eXBvb2wvZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImV4cCI6MTc1NDYwNjc5MSwiaWF0IjoxNzU0NTkyMzkxfQ.dfyaF_v2CH4GS9bvvOHorX0cUoW0bVkN-uS0IZpqyVbqkZ0Kiq83Nj2TJvQyJx--RKMLNRgvyEPVfgUe0xsBWWTvpUIpRFXK-NIdnRJyg6bZNJhz6Rp7_AwzmDsn8Nmc2jexgo8u5rM6oHbLn17aGCr1WxWi1I6wRPdxRg0UsvSCdt5YwhQ2l7hTnTfjhwkcuMmaiL1Jewm3eGmBDCJmMsjWA4AhVVA7E1gUa5V9hCTYFlv6sNMtCL7Wv6YkbA9pO8U87tlM4s4FrV84_wBVjQUiU-cKlhFxATQPL66WaE7-QrrPN_9kbt_6BC5fMiLlG64L4nK0EilTwS_02UNSYQ"
MAC_ADDRESS = "54-43-b2-e9-6d-78"
APPLIANCE_TYPE = "WM"
OS = "android"
APP_VERSION = "2.0.10"


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
    hon = HonConnection()
    try:
        if not await hon.refresh_cognito_token():
            print("❌ Échec de la récupération du cognitoToken.")
            return

        context = await hon.async_get_context(MAC_ADDRESS, APPLIANCE_TYPE)

        output_data = {}

        if context and "shadow" in context and "parameters" in context["shadow"]:
            print("\n📦 Extraction des paramètres numériques…\n")

            parameters = context["shadow"]["parameters"]

            # Parcours tous les paramètres et extrait ceux avec 'parNewVal'
            for key, param in parameters.items():
                if isinstance(param, dict) and "parNewVal" in param:
                    entry = {
                        "value": param.get("parNewVal"),
                        "unit": param.get("unit", ""),
                        "last_update": param.get("lastUpdate")
                    }
                    # Ajout min, max, step si présents
                    if "minimumValue" in param:
                        entry["min"] = param["minimumValue"]
                    if "maximumValue" in param:
                        entry["max"] = param["maximumValue"]
                    if "incrementValue" in param:
                        entry["step"] = param["incrementValue"]

                    output_data[key] = entry

            print(json.dumps(output_data, indent=4, ensure_ascii=False))
        else:
            print("❌ Données non trouvées ou structure incorrecte.")

    finally:
        await hon.async_close()

if __name__ == "__main__":
    asyncio.run(main())