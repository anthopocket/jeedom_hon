#!/usr/bin/env python3
"""
Script de récupération des tokens hOn (id_token et cognito_token)
"""

import asyncio
import logging
import aiohttp
import secrets
import json
import re
import urllib.parse
import sys

_LOGGER = logging.getLogger(__name__)
_LOGGER.setLevel(logging.DEBUG)

# Constantes
AUTH_API = "https://account2.hon-smarthome.com/SmartHome"
API_URL = "https://api-iot.he.services"
APP_VERSION = "2.0.10"
OS_VERSION = 31
OS = "android"
DEVICE_MODEL = "exynos9820"

class HonAuth:
    def __init__(self, email, password):
        self._email = email
        self._password = password
        self._framework = "None"
        self._id_token = ""
        self._cognitoToken = ""
        self._mobile_id = secrets.token_hex(8)
        self._frontdoor_url = ""
        self._header = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                          "(KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36"
        }
        self._session = aiohttp.ClientSession(headers=self._header, connector=aiohttp.TCPConnector(ssl=False))

    async def async_get_frontdoor_url(self, error_code=0):
        data = (
            "message=%7B%22actions%22%3A%5B%7B%22id%22%3A%2279%3Ba%22%2C%22descriptor%22%3A%22apex%3A%2F%2FLightningLoginCustomController%2FACTION%24login%22%2C%22callingDescriptor%22%3A%22markup%3A%2F%2Fc%3AloginForm%22%2C%22params%22%3A%7B%22username%22%3A%22"
            + urllib.parse.quote(self._email)
            + "%22%2C%22password%22%3A%22"
            + urllib.parse.quote(self._password)
            + "%22%2C%22startUrl%22%3A%22%22%7D%7D%5D%7D&aura.context=%7B%22mode%22%3A%22PROD%22%2C%22fwuid%22%3A%22"
            + urllib.parse.quote(self._framework)
            + "%22%2C%22app%22%3A%22siteforce%3AloginApp2%22%2C%22loaded%22%3A%7B%22APPLICATION%40markup%3A%2F%2Fsiteforce%3AloginApp2%22%3A%22YtNc5oyHTOvavSB9Q4rtag%22%7D%2C%22dn%22%3A%5B%5D%2C%22globals%22%3A%7B%7D%2C%22uad%22%3Afalse%7D&aura.pageURI=%2FSmartHome%2Fs%2Flogin%2F%3Flanguage%3Dfr&aura.token=null"
        )

        async with self._session.post(
            f"{AUTH_API}/s/sfsites/aura?r=3&other.LightningLoginCustom.login=1",
            headers={"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
            data=data
        ) as resp:
            if resp.status != 200:
                _LOGGER.error("Unable to connect to the login service: " + str(resp.status))
                return False

            text = await resp.text()
            try:
                json_data = json.loads(text)
                self._frontdoor_url = json_data["events"][0]["attributes"]["values"]["url"]
            except:
                if "clientOutOfSync" in text and error_code != 2:
                    start = text.find("Expected: ") + 10
                    end = text.find(" ", start)
                    self._framework = text[start:end]
                    _LOGGER.debug(f"Framework updated to {self._framework}")
                    return await self.async_get_frontdoor_url(2)
                _LOGGER.error("Unable to retrieve frontdoor URL. Message: " + text)
                return 1
        return 0

    async def get_tokens(self):
        """Récupère les tokens id_token et cognito_token"""
        # Étape 1: Récupération frontdoor URL
        if await self.async_get_frontdoor_url(0) == 1:
            return None

        # Étape 2: Accès frontdoor
        async with self._session.get(self._frontdoor_url) as resp:
            if resp.status != 200:
                _LOGGER.error("Unable to connect to the login service: " + str(resp.status))
                return None
            await resp.text()

        # Étape 3: ProgressiveLogin
        url = f"{AUTH_API}/apex/ProgressiveLogin?retURL=%2FSmartHome%2Fapex%2FCustomCommunitiesLanding"
        async with self._session.get(url) as resp:
            await resp.text()

        # Étape 4: Récupération id_token
        url = f"{AUTH_API}/services/oauth2/authorize?response_type=token+id_token&client_id=3MVG9QDx8IX8nP5T2Ha8ofvlmjLZl5L_gvfbT9.HJvpHGKoAS_dcMN8LYpTSYeVFCraUnV.2Ag1Ki7m4znVO6&redirect_uri=hon%3A%2F%2Fmobilesdk%2Fdetect%2Foauth%2Fdone&display=touch&scope=api%20openid%20refresh_token%20web&nonce=82e9f4d1-140e-4872-9fad-15e25fbf2b7c"
        async with self._session.get(url) as resp:
            text = await resp.text()
            try:
                array = text.split("'", 2)
                if len(array) == 1:
                    m = re.search('id_token\\=(.+?)&', text)
                    if m:
                        self._id_token = m.group(1)
                    else:
                        _LOGGER.error("Unable to get [id_token] during authorization process")
                        return None
                else:
                    params = urllib.parse.parse_qs(array[1])
                    self._id_token = params["id_token"][0]
            except:
                _LOGGER.error("Unable to get [id_token] during authorization process")
                return None

        # Étape 5: Récupération cognito_token
        post_headers = {"id-token": self._id_token}
        data = {
            "appVersion": APP_VERSION,
            "mobileId": self._mobile_id,
            "os": OS,
            "osVersion": str(OS_VERSION),
            "deviceModel": DEVICE_MODEL,
        }

        async with self._session.post(f"{API_URL}/auth/v1/login", headers=post_headers, json=data) as resp:
            try:
                json_data = await resp.json()
                self._cognitoToken = json_data["cognitoUser"]["Token"]
            except Exception as e:
                text = await resp.text()
                _LOGGER.error(f"hOn Invalid Data after sending command {data} with headers {post_headers}. Response: {text}")
                return None

        # Retourne les tokens
        return {
            "id_token": self._id_token,
            "cognito_token": self._cognitoToken,
            "mobile_id": self._mobile_id
        }

    async def close(self):
        await self._session.close()

async def main():
    if len(sys.argv) != 3:
        print("Usage: python hon_get_tokens.py <email> <password>")
        sys.exit(1)
    
    email = sys.argv[1]
    password = sys.argv[2]
    
    auth = HonAuth(email, password)
    
    try:
        tokens = await auth.get_tokens()
        if tokens:
            print(json.dumps(tokens))
        else:
            print("Échec de la récupération des tokens")
            sys.exit(1)
    finally:
        await auth.close()

if __name__ == "__main__":
    asyncio.run(main())