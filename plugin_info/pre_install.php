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

// Fonction appelée avant l'installation du plugin
function hon_pre_install() {
    log::add('hon', 'info', 'Vérifications pré-installation du plugin hOn...');
    
    // Vérifier la version de Jeedom
    $jeedom_version = jeedom::version();
    if (version_compare($jeedom_version, '4.4.0', '<')) {
        log::add('hon', 'error', 'Version de Jeedom insuffisante: ' . $jeedom_version . ' (minimum: 4.4.0)');
        throw new Exception('Jeedom 4.4.0 ou supérieur est requis pour le plugin hOn');
    }
    log::add('hon', 'info', 'Version de Jeedom OK: ' . $jeedom_version);
    
    // Vérifier que PHP a les extensions nécessaires
    $required_extensions = array('json', 'curl');
    $missing_extensions = array();
    
    foreach ($required_extensions as $extension) {
        if (!extension_loaded($extension)) {
            $missing_extensions[] = $extension;
        }
    }
    
    if (!empty($missing_extensions)) {
        log::add('hon', 'error', 'Extensions PHP manquantes: ' . implode(', ', $missing_extensions));
        throw new Exception('Extensions PHP requises: ' . implode(', ', $missing_extensions));
    }
    log::add('hon', 'info', 'Extensions PHP OK');
    
    // Vérifier l'accès en écriture dans le répertoire du plugin
    $plugin_dir = dirname(__FILE__) . '/..';
    if (!is_writable($plugin_dir)) {
        log::add('hon', 'error', 'Répertoire du plugin non accessible en écriture: ' . $plugin_dir);
        throw new Exception('Permissions insuffisantes sur le répertoire du plugin');
    }
    log::add('hon', 'info', 'Permissions du répertoire plugin OK');
    
    // Vérifier la disponibilité de Python 3
    if (!commandExists('python3')) {
        log::add('hon', 'warning', 'Python 3 n\'est pas installé. Installation requise...');
        
        // Tentative d'installation automatique de Python 3
        if (!installPython3()) {
            throw new Exception('Python 3 est requis mais son installation a échoué. Veuillez l\'installer manuellement.');
        }
    } else {
        // Vérifier la version de Python
        $python_version = trim(shell_exec('python3 --version 2>&1'));
        log::add('hon', 'info', 'Python trouvé: ' . $python_version);
        
        // Extraire le numéro de version
        if (preg_match('/Python (\d+\.\d+)/', $python_version, $matches)) {
            $version = $matches[1];
            if (version_compare($version, '3.6', '<')) {
                log::add('hon', 'error', 'Version de Python insuffisante: ' . $version . ' (minimum: 3.6)');
                throw new Exception('Python 3.6 ou supérieur est requis');
            }
        }
    }
    
    // Vérifier la disponibilité de pip3
    if (!commandExists('pip3')) {
        log::add('hon', 'warning', 'pip3 n\'est pas installé. Installation requise...');
        
        if (!installPip3()) {
            throw new Exception('pip3 est requis mais son installation a échoué. Veuillez l\'installer manuellement.');
        }
    } else {
        log::add('hon', 'info', 'pip3 trouvé: ' . trim(shell_exec('pip3 --version 2>&1')));
    }
    
    // Vérifier l'accès réseau (optionnel)
    if (checkNetworkAccess()) {
        log::add('hon', 'info', 'Accès réseau aux serveurs hOn OK');
    } else {
        log::add('hon', 'warning', 'Impossible de vérifier l\'accès aux serveurs hOn (firewall?)');
    }
    
    log::add('hon', 'info', 'Vérifications pré-installation terminées avec succès');
}

// Fonction utilitaire pour vérifier si une commande existe
function commandExists($command) {
    $return = shell_exec("which $command 2>/dev/null");
    return !empty($return);
}

// Fonction pour installer Python 3
function installPython3() {
    log::add('hon', 'info', 'Tentative d\'installation de Python 3...');
    
    // Détecter le système d'exploitation
    $os = php_uname('s');
    
    if (strpos($os, 'Linux') !== false) {
        // Système Linux - utiliser apt-get
        $commands = array(
            'apt-get update',
            'apt-get install -y python3 python3-pip'
        );
        
        foreach ($commands as $cmd) {
            log::add('hon', 'debug', 'Exécution: ' . $cmd);
            $output = shell_exec($cmd . ' 2>&1');
            log::add('hon', 'debug', 'Sortie: ' . $output);
        }
        
        // Vérifier si l'installation a réussi
        if (commandExists('python3')) {
            log::add('hon', 'info', 'Python 3 installé avec succès');
            return true;
        }
    }
    
    log::add('hon', 'error', 'Échec de l\'installation automatique de Python 3');
    return false;
}

// Fonction pour installer pip3
function installPip3() {
    log::add('hon', 'info', 'Tentative d\'installation de pip3...');
    
    $commands = array(
        'apt-get update',
        'apt-get install -y python3-pip'
    );
    
    foreach ($commands as $cmd) {
        log::add('hon', 'debug', 'Exécution: ' . $cmd);
        $output = shell_exec($cmd . ' 2>&1');
        log::add('hon', 'debug', 'Sortie: ' . $output);
    }
    
    if (commandExists('pip3')) {
        log::add('hon', 'info', 'pip3 installé avec succès');
        return true;
    }
    
    log::add('hon', 'error', 'Échec de l\'installation automatique de pip3');
    return false;
}

// Fonction pour vérifier l'accès réseau aux serveurs hOn
function checkNetworkAccess() {
    $hosts = array(
        'account2.hon-smarthome.com',
        'api-iot.he.services'
    );
    
    foreach ($hosts as $host) {
        $fp = @fsockopen($host, 443, $errno, $errstr, 5);
        if (!$fp) {
            log::add('hon', 'debug', 'Impossible de contacter ' . $host . ': ' . $errstr);
            return false;
        }
        fclose($fp);
    }
    
    return true;
}

// Fonction pour vérifier l'espace disque disponible
function checkDiskSpace() {
    $free_bytes = disk_free_space(dirname(__FILE__));
    $free_mb = $free_bytes / 1024 / 1024;
    
    if ($free_mb < 50) { // 50 MB minimum
        log::add('hon', 'warning', 'Espace disque faible: ' . round($free_mb, 2) . ' MB');
        return false;
    }
    
    log::add('hon', 'info', 'Espace disque OK: ' . round($free_mb, 2) . ' MB disponibles');
    return true;
}

// Fonction pour vérifier les permissions système
function checkSystemPermissions() {
    // Vérifier si on peut exécuter des commandes système
    $test_command = 'echo "test" 2>&1';
    $output = shell_exec($test_command);
    
    if (trim($output) !== 'test') {
        log::add('hon', 'error', 'Impossible d\'exécuter des commandes système');
        return false;
    }
    
    log::add('hon', 'info', 'Permissions système OK');
    return true;
}

?>