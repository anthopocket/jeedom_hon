Devellopement d'un plugin jeedom pour l'application HON


1) Recupération des tokens et cognito token via le fichier hon_get_tokens
2) Récupération des infos sur la machine à laver et seche linge via le fichier hon_get_appliances



5) Création JSON avec tous les programmes et les paramétres via le fichier hon_json_generator => Pour le moment il recré token et cognito token.
6) Lancement programme en cherchant dans le json les paramétres via le fichier lancement.py => Pour le moment il recré token et cognito token.
