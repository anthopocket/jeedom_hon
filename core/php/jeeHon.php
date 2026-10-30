<?php
/* Point d'entrée du démon push hOn (resources/hond/hond.py).
 *   ?action=config  → jetons + appareils à écouter (JSON)
 *   ?action=update  → corps JSON {mac_address, success, data} : mise à jour des commandes
 */
require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'hon')) {
    echo 'Clé API non valide';
    die();
}

header('Content-Type: application/json');

switch (init('action')) {
    case 'config':
        echo json_encode(hon::getDaemonConfig());
        break;

    case 'update':
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload) || empty($payload['mac_address'])) {
            echo json_encode(['error' => 'payload invalide']);
            break;
        }
        hon::pushUpdate($payload);
        echo json_encode(['result' => 'ok']);
        break;

    default:
        echo json_encode(['error' => 'action inconnue']);
}
