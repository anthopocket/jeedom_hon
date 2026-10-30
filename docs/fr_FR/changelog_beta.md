# Changelog plugin hOn

>**IMPORTANT**
>
>S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.



# 22/10/2026

- Une seule commande **Programme** (liste déroulante) remplace les commandes « Sélectionner … » (une par programme), qui sont supprimées.
- Paramètres modifiables selon le programme choisi : Température, Temps de lavage, Essorage, Adoucissant auto, Lessive auto (lave-linge) ; Température, Temps de séchage, Niveau de séchage (sèche-linge). Les valeurs figées par le programme sont marquées « (fixe) » et ne sont pas envoyées.
- Noms de programmes en français.
- Correction : le bouton « Rafraîchir » interroge maintenant hOn (il relisait l'état de la dernière synchronisation).
- Correction : « Nom du programme » est déduit des programmes hOn de la machine (l'ancienne table de codes était fausse) ; niveaux de séchage 12/13 corrigés.
- Rafraîchissement automatique en arrière-plan 10 s après Démarrer / Arrêter / Pause / Reprendre.
- **Push temps réel** : démon intégré (sans pyhOn ni dépendance supplémentaire) qui écoute le MQTT hOn et met à jour les infos en quelques secondes ; le cron passe en secours toutes les 10 min quand le démon tourne.
- Commandes (démarrer, pause, reprise, arrêt) envoyées directement depuis PHP avec les jetons en cache : < 1 s au lieu d'environ 5 s ; script Python conservé en secours.
- Stop / Pause / Reprise : utilisation des commandes officielles hOn de chaque appareil (corrige l'arrêt du sèche-linge).
- Le démon écrit dans le log hon ; le rafraîchissement différé n'est utilisé que si le démon est arrêté ; jetons masqués dans le log.
- Message court « Identifiant ou mot de passe incorrect » au test de connexion.
- Nouvelles infos lave-linge : « Alerte lessive » et « Alerte adoucissant » (binaire, 1 = réservoir auto-dosage à remplir).
- Correction : poids lessive/adoucissant, temps de lavage restant et temps de séchage remontés en nombres (au lieu de vrai/faux).
- Correction : au démarrage d'un cycle (détecté par le push), lecture complète de l'état pour afficher toutes les valeurs.
- Correction : machine à l'arrêt → temps restant, lavage principal et heure de fin à 0 (la machine publie la durée du programme affiché sur son écran).
- Correction : la température et l'essorage ne sont plus remis à 0 en fin de cycle (ils restaient bloqués à 0 avec le push).
- Jetons masqués aussi dans la ligne « Sortie tokens » du log.
- Dépendances : installation gérée par Jeedom (venv Python propre au plugin avec aiohttp, script resources/install_apt.sh) ; le démon ne démarre que si elles sont installées.
- Nettoyage : suppression du code et des fichiers inutilisés (méthodes mortes, 7 actions AJAX, fichiers du modèle de plugin, WM/TD_programme.json).

# 15/08/2025

- V1.0- BETA
- création plugin
