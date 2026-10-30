#!/usr/bin/env python3
"""
Démon push hOn pour Jeedom.

Se connecte au broker MQTT de hOn (AWS IoT, via websocket + "custom authorizer")
et transmet à Jeedom chaque changement d'état des appareils, sans attendre le cron.

Aucune dépendance en plus d'aiohttp (déjà utilisé par le plugin) :
le client MQTT 3.1.1 est implémenté ici (CONNECT, SUBSCRIBE, PUBLISH, PING).

Lancement (par hon::deamon_start) :
  python3 hond.py --callback <url jeeHon.php> --apikey <clé> --pid <fichier> --loglevel <niveau>
"""

import argparse
import asyncio
import json
import logging
import os
import secrets
import signal
import struct
import sys
import urllib.parse

import aiohttp

# Réutilise l'extraction de données du cron (même format pour Jeedom)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from hon_cron_updater import HonDataExtractor  # noqa: E402

API_URL = "https://api-iot.he.services"
AWS_ENDPOINT = "a30f6tqw0oh1x0-ats.iot.eu-west-1.amazonaws.com"
AWS_AUTHORIZER = "candy-iot-authorizer"
WS_URL = f"wss://{AWS_ENDPOINT}/mqtt"

KEEPALIVE = 60                 # secondes entre deux PINGREQ
RECONNECT_DELAY = 30           # secondes avant une nouvelle tentative
SESSION_MAX_AGE = 4 * 3600     # reconnexion préventive (jetons renouvelés par Jeedom)

_LOGGER = logging.getLogger("hond")


# ============================================================================
#  Encodage / décodage MQTT 3.1.1 (le strict nécessaire)
# ============================================================================

def _mqtt_str(value):
    data = value.encode('utf-8')
    return struct.pack('!H', len(data)) + data


def _mqtt_len(length):
    out = bytearray()
    while True:
        digit = length % 128
        length //= 128
        if length:
            digit |= 0x80
        out.append(digit)
        if not length:
            return bytes(out)


def _packet(ptype, flags, body=b''):
    return bytes([(ptype << 4) | flags]) + _mqtt_len(len(body)) + body


def mqtt_connect(client_id, username, keepalive=KEEPALIVE):
    flags = 0x80 | 0x02                    # username + clean session
    body = _mqtt_str('MQTT') + b'\x04' + bytes([flags]) + struct.pack('!H', keepalive)
    body += _mqtt_str(client_id) + _mqtt_str(username)
    return _packet(1, 0, body)


def mqtt_subscribe(packet_id, topics, qos=1):
    body = struct.pack('!H', packet_id)
    for topic in topics:
        body += _mqtt_str(topic) + bytes([qos])
    return _packet(8, 0x02, body)


def mqtt_puback(packet_id):
    return _packet(4, 0, struct.pack('!H', packet_id))


MQTT_PINGREQ = _packet(12, 0)
MQTT_DISCONNECT = _packet(14, 0)


class MqttStream:
    """Découpe le flux d'octets reçu (websocket) en paquets MQTT complets"""

    def __init__(self):
        self._buf = bytearray()

    def feed(self, data):
        self._buf.extend(data)
        packets = []
        while len(self._buf) >= 2:
            # longueur restante (1 à 4 octets)
            mult, length, pos = 1, 0, 1
            while True:
                if pos >= len(self._buf):
                    return packets          # entête incomplet
                byte = self._buf[pos]
                length += (byte & 0x7F) * mult
                mult *= 128
                pos += 1
                if not byte & 0x80:
                    break
            if len(self._buf) < pos + length:
                return packets              # paquet incomplet
            header = self._buf[0]
            body = bytes(self._buf[pos:pos + length])
            del self._buf[:pos + length]
            packets.append((header >> 4, header & 0x0F, body))
        return packets


def parse_publish(flags, body):
    qos = (flags >> 1) & 0x03
    tlen = struct.unpack('!H', body[:2])[0]
    topic = body[2:2 + tlen].decode('utf-8')
    pos = 2 + tlen
    packet_id = None
    if qos:
        packet_id = struct.unpack('!H', body[pos:pos + 2])[0]
        pos += 2
    return topic, body[pos:], qos, packet_id


# ============================================================================
#  Démon
# ============================================================================

class HonDaemon:

    def __init__(self, callback, apikey):
        self.callback = callback
        self.apikey = apikey
        self.session = None
        self.devices = {}        # mac (xx-xx-..) -> appliance_type
        self.running = True

    # ---------- dialogue avec Jeedom ----------

    async def jeedom(self, action, payload=None):
        url = f"{self.callback}?apikey={urllib.parse.quote(self.apikey)}&action={action}"
        try:
            if payload is None:
                async with self.session.get(url, timeout=aiohttp.ClientTimeout(total=60)) as resp:
                    text = await resp.text()
            else:
                async with self.session.post(url, json=payload, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                    text = await resp.text()
            return json.loads(text) if text.strip().startswith(('{', '[')) else {'error': text.strip()}
        except Exception as e:
            _LOGGER.error("Appel Jeedom '%s' impossible : %s", action, e)
            return {'error': str(e)}

    # ---------- authentification hOn ----------

    async def get_signature(self, id_token, cognito_token):
        headers = {
            "Content-Type": "application/json",
            "cognito-token": cognito_token,
            "id-token": id_token,
        }
        async with self.session.get(f"{API_URL}/auth/v1/introspection", headers=headers,
                                    timeout=aiohttp.ClientTimeout(total=20)) as resp:
            if resp.status != 200:
                raise RuntimeError(f"introspection HTTP {resp.status}")
            data = await resp.json(content_type=None)
        signature = (data.get('payload') or {}).get('tokenSigned', '')
        if not signature:
            raise RuntimeError("tokenSigned absent de la réponse")
        return signature

    # ---------- session MQTT ----------

    async def run_session(self):
        conf = await self.jeedom('config')
        if conf.get('error') or not conf.get('id_token'):
            raise RuntimeError(f"configuration Jeedom indisponible : {conf.get('error', 'jetons absents')}")

        self.devices = {d['mac']: d.get('type', 'WM') for d in conf.get('devices', [])}
        topics = [t for d in conf.get('devices', []) for t in d.get('topics', [])]
        if not topics:
            raise RuntimeError("aucun appareil à écouter (synchronisation faite ?)")

        signature = await self.get_signature(conf['id_token'], conf['cognito_token'])
        username = ("?x-amz-customauthorizer-name=" + AWS_AUTHORIZER
                    + "&x-amz-customauthorizer-signature=" + urllib.parse.quote(signature, safe='')
                    + "&token=" + conf['id_token'])
        client_id = f"{conf.get('mobile_id') or 'jeedom_hon'}_{secrets.token_hex(8)}"

        url = WS_URL
        _LOGGER.info("Connexion à %s (%d appareil(s))", AWS_ENDPOINT, len(self.devices))
        async with self.session.ws_connect(url, protocols=('mqtt',)) as ws:
            stream = MqttStream()
            await ws.send_bytes(mqtt_connect(client_id, username))

            # CONNACK
            ptype, _, body = await self._next_packet(ws, stream, timeout=20)
            if ptype != 2 or len(body) < 2 or body[1] != 0:
                raise RuntimeError(f"connexion MQTT refusée (code {body[1] if len(body) > 1 else '?'})")
            _LOGGER.info("Connecté au push hOn")

            await ws.send_bytes(mqtt_subscribe(1, topics))
            for t in topics:
                _LOGGER.debug("Abonnement : %s", t)

            loop = asyncio.get_running_loop()
            started = loop.time()
            last_ping = loop.time()
            pending = []
            while self.running:
                if loop.time() - started > SESSION_MAX_AGE:
                    _LOGGER.info("Reconnexion préventive (renouvellement des jetons)")
                    await ws.send_bytes(MQTT_DISCONNECT)
                    return
                if loop.time() - last_ping >= KEEPALIVE:
                    await ws.send_bytes(MQTT_PINGREQ)
                    last_ping = loop.time()

                if not pending:
                    wait = max(1, KEEPALIVE - (loop.time() - last_ping))
                    try:
                        msg = await ws.receive(timeout=wait)
                    except asyncio.TimeoutError:
                        continue                                # rien reçu : on envoie le PING
                    if msg.type == aiohttp.WSMsgType.BINARY:
                        pending = stream.feed(msg.data)
                    elif msg.type in (aiohttp.WSMsgType.CLOSE, aiohttp.WSMsgType.CLOSED,
                                      aiohttp.WSMsgType.CLOSING, aiohttp.WSMsgType.ERROR):
                        raise RuntimeError("connexion fermée par hOn")
                    continue

                ptype, flags, body = pending.pop(0)
                if ptype == 3:                                  # PUBLISH
                    topic, payload, qos, packet_id = parse_publish(flags, body)
                    if qos == 1 and packet_id is not None:
                        await ws.send_bytes(mqtt_puback(packet_id))
                    await self.on_message(topic, payload)
                elif ptype == 9:                                # SUBACK
                    granted = list(body[2:])
                    if 0x80 in granted:
                        _LOGGER.warning("Certains abonnements refusés : %s", granted)
                    else:
                        _LOGGER.info("Abonné à %d sujet(s)", len(granted))
                elif ptype == 13:                               # PINGRESP
                    _LOGGER.debug("PINGRESP")

    async def _next_packet(self, ws, stream, timeout):
        while True:
            msg = await ws.receive(timeout=timeout)
            if msg.type != aiohttp.WSMsgType.BINARY:
                raise RuntimeError(f"réponse inattendue du broker ({msg.type})")
            packets = stream.feed(msg.data)
            if packets:
                return packets[0]

    # ---------- messages reçus ----------

    async def on_message(self, topic, payload):
        try:
            data = json.loads(payload.decode('utf-8'))
        except Exception:
            _LOGGER.debug("Message non JSON sur %s", topic)
            return

        mac = self._mac_from_topic(topic)
        if 'appliancestatus' in topic:
            params = {}
            for p in data.get('parameters', []):
                name = p.get('parName')
                if name is not None:
                    params[name] = {'parNewVal': p.get('parNewVal', p.get('parValue', ''))}
            extracted = HonDataExtractor(self.devices.get(mac, 'WM')).extract_essential_data(
                {'shadow': {'parameters': params}})
            _LOGGER.debug("Push %s : %s", mac, {k: v.get('parNewVal') for k, v in params.items()})
            if extracted:
                await self.jeedom('update', {'mac_address': mac, 'success': True, 'data': extracted})
        elif 'disconnected' in topic:
            _LOGGER.info("%s déconnecté de hOn (%s)", mac, data.get('disconnectReason', ''))
        elif 'connected' in topic:
            _LOGGER.info("%s connecté à hOn", mac)
        else:
            _LOGGER.debug("%s : %s", topic, data)

    def _mac_from_topic(self, topic):
        for mac in self.devices:
            if mac in topic:
                return mac
        return topic.rstrip('/').split('/')[-1]

    # ---------- boucle principale ----------

    async def main(self):
        self.session = aiohttp.ClientSession()
        try:
            while self.running:
                try:
                    await self.run_session()
                except asyncio.CancelledError:
                    raise
                except Exception as e:
                    _LOGGER.warning("Push hOn interrompu : %s", e)
                if self.running:
                    _LOGGER.info("Nouvelle tentative dans %d s", RECONNECT_DELAY)
                    await asyncio.sleep(RECONNECT_DELAY)
        finally:
            await self.session.close()


def main():
    parser = argparse.ArgumentParser(description="Démon push hOn pour Jeedom")
    parser.add_argument('--callback', required=True)
    parser.add_argument('--apikey', required=True)
    parser.add_argument('--pid', required=True)
    parser.add_argument('--loglevel', default='info')
    args = parser.parse_args()

    level = {'debug': logging.DEBUG, 'info': logging.INFO, 'warning': logging.WARNING,
             'error': logging.ERROR}.get(args.loglevel.lower(), logging.INFO)
    logging.basicConfig(level=logging.WARNING, force=True,
                        format='[%(asctime)s] %(levelname)-6s [push] %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    _LOGGER.setLevel(level)

    with open(args.pid, 'w') as f:
        f.write(str(os.getpid()))

    daemon = HonDaemon(args.callback, args.apikey)
    loop = asyncio.new_event_loop()
    task = loop.create_task(daemon.main())

    def stop(*_):
        _LOGGER.info("Arrêt du démon")
        daemon.running = False
        task.cancel()

    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop)
    try:
        loop.run_until_complete(task)
    except asyncio.CancelledError:
        pass
    finally:
        loop.close()
        try:
            os.remove(args.pid)
        except OSError:
            pass


if __name__ == '__main__':
    main()
