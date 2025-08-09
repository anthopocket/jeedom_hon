#!/usr/bin/env python3
"""
Script de récupération des équipements hOn (MAC et infos matériel)
"""

import asyncio
import aiohttp
import json
import sys
import logging

_LOGGER = logging.getLogger(__name__)
_LOGGER.setLevel(logging.DEBUG)

API_URL = "https://api-iot.he.services"

class HonAppliances:
    def __init__(self, id_token, cognito_token):
        self._id_token = id_token
        self._cognito_token = cognito_token
        self._appliances = []
        self._header = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                          "(KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36"
        }
        self._session = aiohttp.ClientSession(headers=self._header, connector=aiohttp.TCPConnector(ssl=False))

    @property
    def _headers(self):
        return {
            "Content-Type": "application/json",
            "cognito-token": self._cognito_token,
            "id-token": self._id_token,
        }

    async def get_appliances(self):
        """Récupère la liste des équipements avec leurs infos"""
        url = f"{API_URL}/commands/v1/appliance"
        async with self._session.get(url, headers=self._headers) as resp:
            try:
                if resp.status != 200:
                    _LOGGER.error(f"Erreur récupération équipements: {resp.status}")
                    return []
                
                json_data = await resp.json()
            except Exception as e:
                _LOGGER.error("hOn Invalid Data after GET " + url)
                return []

            self._appliances = json_data["payload"]["appliances"]
            _LOGGER.debug(f"All appliances: {self._appliances}")

            # Filtre les équipements avec macAddress et applianceTypeId
            self._appliances = [appl for appl in self._appliances if "macAddress" in appl and "applianceTypeId" in appl]
            
            return self._appliances

    def get_appliance_info(self, appliance):
        """Extrait les infos importantes d'un équipement"""
        try:
            return {
                "macAddress": appliance["macAddress"],
                "applianceTypeName": appliance["applianceTypeName"],
                "applianceTypeId": appliance["applianceTypeId"],
                "nickName": appliance.get("nickName", f"Device ID: {appliance['applianceTypeId']}"),
                "brand": appliance["brand"],
                "modelName": appliance["modelName"],
                "fwVersion": appliance["fwVersion"],
                "series": appliance.get("series", "N/A"),
                "connectivity": appliance.get("connectivity", "N/A")
            }
        except Exception as e:
            _LOGGER.error(f"Erreur extraction infos équipement: {e}")
            return None

    def log_appliance_details(self, appliance):
        """Affiche les détails complets d'un équipement"""
        info = self.get_appliance_info(appliance)
        if info:
            name = info['nickName']
            mac = info['macAddress']
            print(f"\nDétails de l'appareil '{name}' (MAC: {mac}):")
            for key, value in appliance.items():
                print(f"  {key}: {value}")

    async def close(self):
        await self._session.close()

async def main():
    if len(sys.argv) != 3:
        print("Usage: python hon_get_appliances.py <id_token> <cognito_token>")
        sys.exit(1)
    
    id_token = sys.argv[1]
    cognito_token = sys.argv[2]
    
    hon_appliances = HonAppliances(id_token, cognito_token)
    
    try:
        appliances = await hon_appliances.get_appliances()
        
        if appliances:
            print("Équipements récupérés :")
            for appl in appliances:
                info = hon_appliances.get_appliance_info(appl)
                if info:
                    print(f"- {info['applianceTypeId']} ({info['macAddress']}) - {info['nickName']}")
            
            print("\n" + "="*50)
            
            # Affiche les détails complets de chaque équipement
            for appl in appliances:
                hon_appliances.log_appliance_details(appl)
        else:
            print("Aucun équipement trouvé")
            
    finally:
        await hon_appliances.close()

if __name__ == "__main__":
    asyncio.run(main())