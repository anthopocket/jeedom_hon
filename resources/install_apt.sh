#!/bin/bash
# Installation des dépendances du plugin hOn : venv Python propre au plugin avec aiohttp
# Appelé par Jeedom (hon::dependancy_install) avec le fichier de progression en argument.
PROGRESS_FILE=${1:-/tmp/jeedom/hon/dependency}
BASEDIR=$(cd "$(dirname "$0")" && pwd)
VENV="$BASEDIR/venv"

mkdir -p "$(dirname "$PROGRESS_FILE")"
echo 0 > "$PROGRESS_FILE"
echo "********************************************************"
echo "*        Installation des dépendances hOn              *"
echo "********************************************************"
date

echo 10 > "$PROGRESS_FILE"
echo "== Paquets système (python3-venv, python3-pip)"
sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y python3 python3-venv python3-pip

echo 40 > "$PROGRESS_FILE"
echo "== Création de l'environnement Python : $VENV"
if [ ! -x "$VENV/bin/python3" ]; then
    sudo rm -rf "$VENV"
    sudo python3 -m venv "$VENV"
fi

echo 60 > "$PROGRESS_FILE"
echo "== Installation d'aiohttp"
sudo "$VENV/bin/python3" -m pip install --upgrade pip
sudo "$VENV/bin/python3" -m pip install --upgrade aiohttp

echo 90 > "$PROGRESS_FILE"
sudo chown -R www-data:www-data "$VENV"
"$VENV/bin/python3" -c "import aiohttp; print('aiohttp', aiohttp.__version__)"

echo 100 > "$PROGRESS_FILE"
date
echo "********************************************************"
echo "*        Installation terminée                         *"
echo "********************************************************"
rm -f "$PROGRESS_FILE"
