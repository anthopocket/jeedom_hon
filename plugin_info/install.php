<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function hon_install() {
    // Création des répertoires nécessaires
    $resourcesPath = dirname(__FILE__) . '/../resources';
    if (!file_exists($resourcesPath)) {
        mkdir($resourcesPath, 0755, true);
    }
    
    // Vérification des scripts Python
    $requiredScripts = [
        'hon_get_tokens.py',
        'hon_get_appliances.py', 
        'hon_device_ids.py',
        'hon_wash_dryer_status.py',
        'hon_wash_dryer_numbers.py',
        'hon_wash_dryer_actions.py'
    ];
    
    foreach ($requiredScripts as $script) {
        $scriptPath = $resourcesPath . '/' . $script;
        if (!file_exists($scriptPath)) {
            log::add('hon', 'warning', "Script Python manquant: $script");
        } else {
            chmod($scriptPath, 0755);
        }
    }
    
    log::add('hon', 'info', 'Installation du plugin hOn terminée');
}

function hon_update() {
    // Mise à jour des permissions des scripts
    $resourcesPath = dirname(__FILE__) . '/../resources';
    if (file_exists($resourcesPath)) {
        $scripts = glob($resourcesPath . '/*.py');
        foreach ($scripts as $script) {
            chmod($script, 0755);
        }
    }
    
    log::add('hon', 'info', 'Mise à jour du plugin hOn terminée');
}

function hon_remove() {
    // Nettoyage lors de la suppression
    log::add('hon', 'info', 'Suppression du plugin hOn');
}

?>