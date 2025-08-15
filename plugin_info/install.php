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

// Fonction appelée lors de l'installation du plugin
function hon_install() {
    log::add('hon', 'info', '🚀 Installation du plugin hOn...');
    
    try {
        // Créer les répertoires nécessaires
        $dataDir = __DIR__ . '/../data';
        $resourcesDir = __DIR__ . '/../resources';
        
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0755, true)) {
                throw new Exception('Impossible de créer le répertoire data');
            }
            log::add('hon', 'info', '📁 Répertoire data créé: ' . $dataDir);
        }
        
        if (!is_dir($resourcesDir)) {
            if (!mkdir($resourcesDir, 0755, true)) {
                throw new Exception('Impossible de créer le répertoire resources');
            }
            log::add('hon', 'info', '📁 Répertoire resources créé: ' . $resourcesDir);
        }
        
        // Vérifier les permissions
        if (!is_writable($dataDir)) {
            chmod($dataDir, 0755);
            log::add('hon', 'info', '🔐 Permissions data ajustées');
        }
        
        if (!is_writable($resourcesDir)) {
            chmod($resourcesDir, 0755);
            log::add('hon', 'info', '🔐 Permissions resources ajustées');
        }
        
        // Créer le fichier .gitkeep pour conserver les répertoires
        file_put_contents($dataDir . '/.gitkeep', '');
        file_put_contents($resourcesDir . '/.gitkeep', '');
        
        // Configuration par défaut
        config::save('refreshInterval', 300, 'hon'); // 5 minutes
        config::save('commandTimeout', 30, 'hon');   // 30 secondes
        config::save('debug', false, 'hon');
        
        log::add('hon', 'info', '✅ Installation du plugin hOn terminée avec succès');
        
    } catch (Exception $e) {
        log::add('hon', 'error', '❌ Erreur lors de l\'installation: ' . $e->getMessage());
        throw $e;
    }
}

// Fonction appelée lors de la mise à jour du plugin
function hon_update() {
    log::add('hon', 'info', '🔄 Mise à jour du plugin hOn...');
    
    try {
        // Vérifier et créer les répertoires manquants
        $dataDir = __DIR__ . '/../data';
        $resourcesDir = __DIR__ . '/../resources';
        
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
            log::add('hon', 'info', '📁 Répertoire data créé lors de la mise à jour');
        }
        
        if (!is_dir($resourcesDir)) {
            mkdir($resourcesDir, 0755, true);
            log::add('hon', 'info', '📁 Répertoire resources créé lors de la mise à jour');
        }
        
        // Mise à jour des permissions
        chmod($dataDir, 0755);
        chmod($resourcesDir, 0755);
        
        // Nettoyer les anciens fichiers temporaires
        $tempFiles = glob($dataDir . '/temp_*');
        foreach ($tempFiles as $tempFile) {
            if (is_file($tempFile) && (time() - filemtime($tempFile)) > 3600) { // Plus d'1 heure
                unlink($tempFile);
                log::add('hon', 'info', '🗑️ Fichier temporaire supprimé: ' . basename($tempFile));
            }
        }
        
        // Vérifier les configurations par défaut
        if (config::byKey('refreshInterval', 'hon') == '') {
            config::save('refreshInterval', 300, 'hon');
        }
        if (config::byKey('commandTimeout', 'hon') == '') {
            config::save('commandTimeout', 30, 'hon');
        }
        
        log::add('hon', 'info', '✅ Mise à jour du plugin hOn terminée avec succès');
        
    } catch (Exception $e) {
        log::add('hon', 'error', '❌ Erreur lors de la mise à jour: ' . $e->getMessage());
        throw $e;
    }
}

// Fonction appelée lors de la désinstallation du plugin
function hon_remove() {
    log::add('hon', 'info', '🗑️ Désinstallation du plugin hOn...');
    
    try {
        // Nettoyer les configurations
        config::remove('*', 'hon');
        log::add('hon', 'info', '🧹 Configurations supprimées');
        
        // Optionnel: supprimer les données (décommenté si souhaité)
        /*
        $dataDir = __DIR__ . '/../data';
        if (is_dir($dataDir)) {
            $files = glob($dataDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dataDir);
            log::add('hon', 'info', '📁 Répertoire data supprimé');
        }
        */
        
        log::add('hon', 'info', '✅ Désinstallation du plugin hOn terminée');
        log::add('hon', 'info', 'ℹ️ Les fichiers de données ont été conservés dans le dossier data/');
        
    } catch (Exception $e) {
        log::add('hon', 'error', '❌ Erreur lors de la désinstallation: ' . $e->getMessage());
        throw $e;
    }
}

?>