import asyncio
import aiohttp
import logging
import json

# Configuration du logger pour le débogage
logging.basicConfig(level=logging.DEBUG)
_LOGGER = logging.getLogger(__name__)

# Constantes
API_URL = "https://api-iot.he.services"
ID_TOKEN = "eyJraWQiOiIyNTYiLCJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdF9oYXNoIjoiWTJlWWlnX1JldjRoRHJjUVpNVy0zZyIsInN1YiI6Imh0dHBzOi8vbG9naW4uc2FsZXNmb3JjZS5jb20vaWQvMDBEVTAwMDAwMDBMa2NxTUFDLzAwNTY4MDAwMDA2RzYwQkFBUyIsInpvbmVpbmZvIjoiRXVyb3BlL1BhcmlzIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsImFkZHJlc3MiOnt9LCJwcm9maWxlIjoiaHR0cHM6Ly9oYWllcmV1cm9wZS5teS5zYWxlc2ZvcmNlLmNvbS8wMDU2ODAwMDAwNkc2MEJBQVMiLCJpc3MiOiJodHRwczovL2FjY291bnQyLmhvbi1zbWFydGhvbWUuY29tL1NtYXJ0SG9tZSIsInByZWZlcnJlZF91c2VybmFtZSI6ImFudGhvbnkucG9uZGVwZXlyZUBnbWFpbC5jb20iLCJnaXZlbl9uYW1lIjoiQW50aG9ueSIsImxvY2FsZSI6ImVuX0dCIiwibm9uY2UiOiI4MmU5ZjRkMS0xNDBlLTQ4NzItOWZhZC0xNWUyNWZiZjJiN2MiLCJwaWN0dXJlIjoiaHR0cHM6Ly9hY2NvdW50Mi5ob24tc21hcnRob21lLmNvbS9pbWcvdXNlcnByb2ZpbGUvZGVmYXVsdF9wcm9maWxlXzIwMF92Mi5wbmciLCJjdXN0b21fYXR0cmlidXRlcyI6eyJQcml2YWN5VXBkYXRlZCI6InRydWUiLCJQZXJzb25Db250YWN0SWQiOiIwMDM2ODAwMDAwWGRpSVZBQVoiLCJKV1QiOiIiLCJFeHRlcm5hbFNvdXJjZSI6IklvVCBNb2JpbGUgQXBwIiwiRXh0ZXJuYWxTdWJTb3VyY2UiOiJoT24iLCJQZXJzb25BY2NvdW50SWQiOiIwMDE2ODAwMDAwZFIyTGpBQUsiLCJDb3VudHJ5IjoiRlIiLCJVc2VyTGFuZ3VhZ2UiOiJlbl9HQiJ9LCJhdWQiOiIzTVZHOVFEeDhJWDhuUDVUMkhhOG9mdmxtakxabDVMX2d2ZmJUOS5ISnZwSEdLb0FTX2RjTU44TFlwVFNZZVZGQ3JhVW5WLjJBZzFLaTdtNHpuVk82LG15bW9iaWxlYXBwOi8vY2FsbGJhY2ssY2FuZHk6Ly9tb2JpbGVzZGsvZGV0ZWN0L29hdXRoL2RvbmUsaG9vdmVyOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLHJvc2llcmVzOi8vbW9iaWxlc2RrL2RldGVjdC9vYXV0aC9kb25lLGhvbjovL21vYmlsZXNkay9kZXRlY3Qvb2F1dGgvZG9uZSxodHRwczovL2FwcC5nZXRwb3N0bWFuLmNvbS9vYXV0aDIvY2FsbGJhY2ssIiwidXBkYXRlZF9hdCI6IjIwMjUtMDctMTRUMTg6MDA6MjhaIiwibmlja25hbWUiOiJVc2VyMTcwMDMwOTc4MTIyNjY2MTE5MTMiLCJuYW1lIjoiQW50aG9ueSBQb25kZXBleXJlIiwicGhvbmVfbnVtYmVyIjpudWxsLCJleHAiOjE3NTQ2OTczMjYsImlhdCI6MTc1NDY2ODUyNiwiZmFtaWx5X25hbWUiOiJQb25kZXBleXJlIiwiZW1haWwiOiJhbnRob255LnBvbmRlcGV5cmVAZ21haWwuY29tIn0.ZDnongv0ThOtpM8E5RrYRcNTcWEUSDru3qvMrs0v404dEEfx5KrT4wTA9ma1nVfIrHyyl8KK_FUk7R7GJUhQO7I3iiVo_2vJb3H9SWRePwm5o9MxLtvD_MindNAi6ST3pT6BhxdJHv4_xd4sNZbwjkzfvZk284NHxK3hJeHGWqrHFGvihQ9P9k_Wm5bj3blm1L4AWdXOZ9_Su7ALPM1OsK3oYxp7o_fUjFZaN2AJJjD7m6rI_DK5KtMNoMa1xNLI0GXfqDy1lTXpnZefT3sHtPUF3PUnUT-RPlirfDp4yAK-9nA_IYiLczZss2ted4C8M7ohlqv9FEhZ4KE_zJJ5Evsh3vGzcuzQe2HnQBLMbCQZrqQ4BUFH-2WaNtPEoLHYyKza1bNHMrKBuhjWr_YMokgUkKwne0z9OxmhpThgC6lIl9xk8FvffhU4Zo3hw-g4v2AzYhuPaKZWdN_xP3r5DZAz3Sj7l72SLQ0gfp9UeuUpI0cp5Fxz6XP1fnLIx3YCRIyB0FvtEbiFWwG1XgodW5v2TGCDHVxF_YScpTFmT9T6g5JYdNTtGkM4cpitkyjKPluq6qoNW4BIbN78ZFs5oTziX3rJx0Jgw9220KYK-9lTQLHGC3PVSSe2eQZ77DyYS5AXhKady0hkCp4vhJMkL8tqRR51amo4s_3ovoR3LWE"
COGNITO_TOKEN = "eyJraWQiOiJldS13ZXN0LTEtOCIsInR5cCI6IkpXUyIsImFsZyI6IlJTNTEyIn0.eyJzdWIiOiJldS13ZXN0LTE6MDQ3NDhkN2EtMjAyYi00ZDM0LTk2YjktYTM2NjlhOTQ1OTRmIiwiYXVkIjoiZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImFtciI6WyJhdXRoZW50aWNhdGVkIiwibG9naW4uaGFpZXIuYXBwIiwibG9naW4uaGFpZXIuYXBwOmV1LXdlc3QtMTpkOGNjYTFkYi05ZmUzLTQ1ZGYtYmZjOC01MmM4ODQwNThlNjI6MDAxNjgwMDAwMGRSMkxqQUFLIl0sImlzcyI6Imh0dHBzOi8vY29nbml0by1pZGVudGl0eS5hbWF6b25hd3MuY29tIiwiaHR0cHM6Ly9jb2duaXRvLWlkZW50aXR5LmFtYXpvbmF3cy5jb20vaWRlbnRpdHktcG9vbC1hcm4iOiJhcm46YXdzOmNvZ25pdG8taWRlbnRpdHk6ZXUtd2VzdC0xOjgyODc5NDkxMzc4NjppZGVudGl0eXBvb2wvZXUtd2VzdC0xOmQ4Y2NhMWRiLTlmZTMtNDVkZi1iZmM4LTUyYzg4NDA1OGU2MiIsImV4cCI6MTc1NDY4MjkyNywiaWF0IjoxNzU0NjY4NTI3fQ.Ib2ZDLKVZnCWdbuGjNIz3ihZ56yjPoEQ_IW9OPEfnKmyP7box6qhJtdokxELXujlFrjCVjvwJXW_NBNgLUtRxtfeGKKS5bJ1LZMfoTxINSS11H3q1RZUGFCW3pQnFNivK_53n7hhWkf4n58-p4sN_ADzKbDwvuVHdwTQDSA6G-t60Ake8Ojip9K05vI_zBVZfH7MygNj5S50cGhHF1WeD36huTecBYPpvbDs0_JshdBpGLE6IrR7lW5jM9a_0qOezhIh2GmKRqAklS0S5xJMHRZydzWEmslMTk3EkMvwhdvV6KU4l8qoEleIDFBMkQ4pF6fy_74vx_CsjmcyjWQXhQ"
MAC_ADDRESS = "54-43-b2-e9-6d-78"
APPLIANCE_TYPE = "WM"

class HonConnection:
    def __init__(self):
        # Initialisation de la session HTTP
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

    async def async_get_context(self, mac_address, appliance_type):
        # Vérifie si la session existe, sinon la crée
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
    # Créer une instance de HonConnection
    hon = HonConnection()

    try:
        # Récupérer les données de l'appareil
        context = await hon.async_get_context(MAC_ADDRESS, APPLIANCE_TYPE)
        
        if context and "shadow" in context and "parameters" in context["shadow"]:
            print("\nÉtat du lave-linge:")
            # Liste des attributs binaires à afficher (basée sur binary.py)
            binary_attributes = [
                "onOffStatus", "doorStatus", "doorStatusZ1", "doorStatusZ2", "lightStatus",
                "doorLockStatus", "lockStatus", "remoteCtrValid", "preheatStatus",
                "healthMode", "saltStatus", "rinseAidStatus", "defrostStatus"
            ]
            parameters = context["shadow"]["parameters"]
            for key in binary_attributes:
                if key in parameters:
                    value = parameters[key]["parNewVal"]
                    status = "Activé/Ouvert" if value == "1" else "Désactivé/Fermé"
                    if key in ["doorLockStatus", "lockStatus"]:  # Inversion pour les verrouillages
                        status = "Déverrouillé" if value == "0" else "Verrouillé"
                    print(f"{key}: {status} (mis à jour: {parameters[key]['lastUpdate']})")
                elif key == "onOffStatus" and "lastConnEvent" in context:
                    # Fallback pour onOffStatus si non présent
                    status = "Connecté" if context["lastConnEvent"]["category"] == "CONNECTED" else "Déconnecté"
                    print(f"{key} (via lastConnEvent): {status} (mis à jour: {context['lastConnEvent']['instantTime']})")
            # Afficher l'état de pause (pertinent pour le contexte)
            if "pause" in parameters:
                status = "En pause" if parameters["pause"]["parNewVal"] == "1" else "Non en pause"
                print(f"pause: {status} (mis à jour: {parameters['pause']['lastUpdate']})")
        else:
            print("Aucune donnée récupérée ou structure inattendue. Vérifie les tokens ou l'appareil.")
    
    finally:
        # Fermer la session
        await hon.async_close()

# Exécuter le programme
if __name__ == "__main__":
    asyncio.run(main())