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

// Variables globales
var currentEqLogicId = null;
var availablePrograms = [];

// Initialisation au chargement de la page
$(document).ready(function() {
    console.log('🚀 Initialisation du plugin hOn');
    
    // Actualiser le statut des tokens au chargement
    refreshTokenStatus();
    
    // Actualiser toutes les 5 minutes
    setInterval(refreshTokenStatus, 300000);
});

// ========================================
// GESTION DES ÉVÉNEMENTS
// ========================================

// Synchronisation des équipements
$('.bt_syncHonEquipments').off('click').on('click', function() {
    console.log('🔄 Démarrage synchronisation des équipements');
    
    $.hideAlert();
    
    bootbox.confirm('{{Voulez-vous synchroniser les équipements hOn ? Cela peut prendre quelques minutes.}}', function(result) {
        if (result) {
            syncHonEquipments();
        }
    });
});

// Régénération des JSONs
$('.bt_regenerateJsons').off('click').on('click', function() {
    console.log('📄 Régénération des fichiers JSON');
    
    $.hideAlert();
    
    bootbox.confirm('{{Voulez-vous régénérer les fichiers JSON ? Cela mettra à jour les programmes disponibles.}}', function(result) {
        if (result) {
            regenerateJsonFiles();
        }
    });
});

// Actualisation des tokens
$('.bt_refreshTokens').off('click').on('click', function() {
    console.log('🔑 Actualisation des tokens');
    
    $.hideAlert();
    refreshHonTokens();
});

// Actualisation d'un équipement individuel
$(document).off('click', '.bt_refreshDevice').on('click', '.bt_refreshDevice', function() {
    var eqLogicId = $('.eqLogic').attr('data-eqLogic_id');
    if (eqLogicId) {
        console.log('🔄 Rafraîchissement équipement ID:', eqLogicId);
        refreshDeviceInfo(eqLogicId);
    }
});

// Affichage des fichiers JSON
$(document).off('click', '.bt_showJsonFiles').on('click', '.bt_showJsonFiles', function() {
    var eqLogicId = $('.eqLogic').attr('data-eqLogic_id');
    if (eqLogicId) {
        console.log('📄 Affichage des JSONs pour équipement ID:', eqLogicId);
        showJsonFiles(eqLogicId);
    }
});

// Gestion des onglets
$('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
    var target = $(e.target).attr('href');
    console.log('📋 Onglet activé:', target);
    
    var eqLogicId = $('.eqLogic').attr('data-eqLogic_id');
    
    if (target === '#programtab' && eqLogicId) {
        loadAvailablePrograms(eqLogicId);
    } else if (target === '#statustab' && eqLogicId) {
        loadDeviceStatus(eqLogicId);
    }
});

// Gestion de la sélection d'équipement
$(document).off('click', '.eqLogicDisplayCard').on('click', '.eqLogicDisplayCard', function() {
    var eqLogicId = $(this).attr('data-eqLogic_id');
    currentEqLogicId = eqLogicId;
    console.log('🎯 Sélection équipement ID:', eqLogicId);
});

// ========================================
// FONCTIONS AJAX
// ========================================

// Synchronisation des équipements
function syncHonEquipments() {
    console.log('🔄 Lancement de la synchronisation...');
    
    $('#md_modal').dialog({title: "{{Synchronisation en cours}}"});
    $('#md_modal').load('index.php?v=d&plugin=hon&modal=hon.sync').dialog('open');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'syncDevices'
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
            $('#md_modal').dialog('close');
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                $('#md_modal').dialog('close');
                return;
            }
            
            console.log('✅ Synchronisation réussie:', data.result);
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            $('#md_modal').dialog('close');
            
            // Recharger la page pour afficher les nouveaux équipements
            setTimeout(function() {
                window.location.reload();
            }, 2000);
        }
    });
}

// Régénération des fichiers JSON
function regenerateJsonFiles() {
    console.log('📄 Régénération des fichiers JSON...');
    
    $('#md_modal').dialog({title: "{{Régénération des fichiers JSON}}"});
    $('#md_modal').load('index.php?v=d&plugin=hon&modal=hon.regenerate').dialog('open');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'regenerateJsons'
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
            $('#md_modal').dialog('close');
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                $('#md_modal').dialog('close');
                return;
            }
            
            console.log('✅ Régénération réussie:', data.result);
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            $('#md_modal').dialog('close');
        }
    });
}

// Actualisation des tokens
function refreshHonTokens() {
    console.log('🔑 Actualisation des tokens...');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'refreshTokens'
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            console.log('✅ Tokens actualisés:', data.result);
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            
            // Actualiser l'affichage du statut
            setTimeout(refreshTokenStatus, 1000);
        }
    });
}

// Statut des tokens
function refreshTokenStatus() {
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'getTokenStatus'
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            console.log('⚠️ Erreur récupération statut tokens:', error);
        },
        success: function(data) {
            if (data.state == 'ok') {
                updateTokenStatusDisplay(data.result);
            }
        }
    });
}

// Mise à jour de l'affichage du statut des tokens
function updateTokenStatusDisplay(tokenStatus) {
    var alertElement = $('.alert:contains("Statut des tokens")').first();
    if (alertElement.length) {
        var alertClass = tokenStatus.valid ? 'alert-success' : 'alert-warning';
        var labelClass = tokenStatus.valid ? 'label-success' : 'label-warning';
        var labelText = tokenStatus.valid ? '{{Valides}}' : '{{Expirés}}';
        
        alertElement.removeClass('alert-success alert-warning').addClass(alertClass);
        alertElement.find('.label').removeClass('label-success label-warning').addClass(labelClass).text(labelText);
        
        // Mettre à jour les informations
        var ageHours = Math.round(tokenStatus.age / 3600 * 10) / 10;
        alertElement.find('small').first().html(
            '{{Dernière mise à jour}} : ' + tokenStatus.lastUpdate + 
            ' ({{il y a}} ' + ageHours + ' {{heures}})'
        );
        
        if (tokenStatus.valid && tokenStatus.expireIn) {
            var expireHours = Math.round(tokenStatus.expireIn / 3600 * 10) / 10;
            var expireInfo = alertElement.find('small').last();
            if (expireInfo.length === 0) {
                alertElement.find('.col-sm-4').append('<br><small>{{Expire dans}} ' + expireHours + 'h</small>');
            } else {
                expireInfo.text('{{Expire dans}} ' + expireHours + 'h');
            }
        }
    }
}

// Rafraîchissement d'un équipement
function refreshDeviceInfo(eqLogicId) {
    console.log('🔄 Rafraîchissement équipement:', eqLogicId);
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'refreshInfo',
            id: eqLogicId
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            console.log('✅ Équipement rafraîchi');
            $('#div_alert').showAlert({message: '{{Équipement rafraîchi avec succès}}', level: 'success'});
            
            // Recharger les onglets actifs
            var activeTab = $('.nav-tabs .active a').attr('href');
            if (activeTab === '#statustab') {
                loadDeviceStatus(eqLogicId);
            }
        }
    });
}

// Chargement des programmes disponibles
function loadAvailablePrograms(eqLogicId) {
    console.log('📋 Chargement programmes pour équipement:', eqLogicId);
    
    $('#programsList').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> {{Chargement des programmes...}}</div>');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'getAvailablePrograms',
            id: eqLogicId
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            $('#programsList').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> {{Erreur lors du chargement des programmes}}</div>');
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#programsList').html('<div class="alert alert-danger">' + data.result + '</div>');
                return;
            }
            
            availablePrograms = data.result;
            displayAvailablePrograms(data.result);
        }
    });
}

// Affichage des programmes disponibles
function displayAvailablePrograms(programs) {
    var html = '';
    
    if (programs.length === 0) {
        html = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> {{Aucun programme disponible}}</div>';
    } else {
        html = '<div class="row">';
        
        programs.forEach(function(program, index) {
            var colClass = programs.length <= 3 ? 'col-md-4' : 'col-md-3';
            
            html += '<div class="' + colClass + ' col-sm-6">';
            html += '<div class="panel panel-default program-card" style="margin-bottom: 15px;">';
            html += '<div class="panel-body text-center">';
            html += '<h4><i class="fas fa-play-circle"></i> ' + program.display_name + '</h4>';
            html += '<p class="text-muted">' + (program.description || 'Programme ' + program.name) + '</p>';
            
            if (program.configurable_parameters && program.configurable_parameters.length > 0) {
                html += '<small class="text-info"><i class="fas fa-cogs"></i> ' + program.configurable_parameters.length + ' {{paramètres configurables}}</small><br>';
            }
            
            html += '<br><button class="btn btn-primary btn-sm bt_launchProgram" data-program="' + program.name + '">';
            html += '<i class="fas fa-play"></i> {{Lancer}}';
            html += '</button>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        });
        
        html += '</div>';
    }
    
    $('#programsList').html(html);
}

// Lancement de programme
$(document).off('click', '.bt_launchProgram').on('click', '.bt_launchProgram', function() {
    var programName = $(this).data('program');
    var program = availablePrograms.find(p => p.name === programName);
    
    if (!program) {
        $('#div_alert').showAlert({message: '{{Programme non trouvé}}', level: 'danger'});
        return;
    }
    
    console.log('🚀 Préparation lancement programme:', programName);
    
    // Préparer le modal de lancement
    $('#programSelect').val(programName);
    $('#programModal .modal-title').html('<i class="fas fa-play-circle"></i> {{Lancer}} ' + program.display_name);
    
    // Construire les paramètres configurables
    var parametersHtml = '';
    if (program.configurable_parameters && program.configurable_parameters.length > 0) {
        program.configurable_parameters.forEach(function(paramName) {
            var defaultValue = program.default_parameters[paramName] || '';
            parametersHtml += '<div class="form-group">';
            parametersHtml += '<label>' + paramName + '</label>';
            parametersHtml += '<input type="text" class="form-control program-param" data-param="' + paramName + '" value="' + defaultValue + '" placeholder="' + defaultValue + '">';
            parametersHtml += '</div>';
        });
    } else {
        parametersHtml = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> {{Ce programme n\'a pas de paramètres configurables}}</div>';
    }
    
    $('#programParameters').html(parametersHtml);
    $('#programModal').modal('show');
});

// Confirmer le lancement du programme
$('#launchProgram').off('click').on('click', function() {
    var programName = $('#programSelect').val();
    var parameters = {};
    
    // Récupérer les paramètres
    $('.program-param').each(function() {
        var paramName = $(this).data('param');
        var paramValue = $(this).val();
        if (paramValue) {
            parameters[paramName] = paramValue;
        }
    });
    
    console.log('🚀 Lancement programme:', programName, 'avec paramètres:', parameters);
    
    $('#programModal').modal('hide');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'launchProgram',
            id: currentEqLogicId,
            program: programName,
            parameters: parameters
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            console.log('✅ Programme lancé:', data.result);
            $('#div_alert').showAlert({message: data.result, level: 'success'});
        }
    });
});

// Chargement du statut de l'équipement
function loadDeviceStatus(eqLogicId) {
    console.log('📊 Chargement statut équipement:', eqLogicId);
    
    $('#deviceStatus').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> {{Chargement du statut...}}</div>');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'getDeviceStatus',
            id: eqLogicId
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            $('#deviceStatus').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> {{Erreur lors du chargement du statut}}</div>');
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#deviceStatus').html('<div class="alert alert-danger">' + data.result + '</div>');
                return;
            }
            
            displayDeviceStatus(data.result);
        }
    });
}

// Affichage du statut de l'équipement
function displayDeviceStatus(status) {
    var html = '<div class="row">';
    
    // Statut de connexion
    html += '<div class="col-md-6">';
    html += '<div class="panel panel-default">';
    html += '<div class="panel-heading"><i class="fas fa-wifi"></i> {{Connexion}}</div>';
    html += '<div class="panel-body">';
    
    var connectionClass = '';
    var connectionIcon = '';
    var connectionText = '';
    
    switch (status.connection_status) {
        case 'connected':
            connectionClass = 'text-success';
            connectionIcon = 'fas fa-check-circle';
            connectionText = '{{Connecté}}';
            break;
        case 'disconnected':
            connectionClass = 'text-warning';
            connectionIcon = 'fas fa-exclamation-triangle';
            connectionText = '{{Déconnecté}}';
            break;
        default:
            connectionClass = 'text-muted';
            connectionIcon = 'fas fa-question-circle';
            connectionText = '{{Statut inconnu}}';
            break;
    }
    
    html += '<h4 class="' + connectionClass + '"><i class="' + connectionIcon + '"></i> ' + connectionText + '</h4>';
    html += '</div>';
    html += '</div>';
    html += '</div>';
    
    // Informations générales
    html += '<div class="col-md-6">';
    html += '<div class="panel panel-default">';
    html += '<div class="panel-heading"><i class="fas fa-info-circle"></i> {{Informations}}</div>';
    html += '<div class="panel-body">';
    html += '<strong>{{Nom}}</strong>: ' + (status.device_name || 'N/A') + '<br>';
    html += '<strong>{{Commandes}}</strong>: ' + (status.commands_count || '0') + '<br>';
    html += '<strong>{{Extraction}}</strong>: ' + (status.extraction_time || 'N/A');
    html += '</div>';
    html += '</div>';
    html += '</div>';
    
    html += '</div>';
    
    $('#deviceStatus').html(html);
}

// Affichage des fichiers JSON
function showJsonFiles(eqLogicId) {
    console.log('📄 Affichage fichiers JSON pour équipement:', eqLogicId);
    
    // Récupérer les informations de l'équipement
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'getDeviceInfo',
            id: eqLogicId
        },
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            handleError(error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            var deviceInfo = data.result;
            var html = '<div class="alert alert-info">';
            html += '<i class="fas fa-info-circle"></i> ';
            html += '{{Fichiers JSON associés à cet équipement}} (' + deviceInfo.macAddress + ')';
            html += '</div>';
            
            html += '<h4><i class="fas fa-file-code"></i> {{Fichier d\'informations de l\'appareil}}</h4>';
            html += '<code>hon/data/device_' + deviceInfo.mac_filename + '.json</code>';
            html += '<p class="text-muted">{{Contient toutes les informations détaillées de l\'appareil, commandes disponibles, statut de connexion, etc.}}</p>';
            
            html += '<h4><i class="fas fa-list"></i> {{Fichiers des programmes}}</h4>';
            html += '<p class="text-muted">{{Un fichier JSON par programme disponible :}}</p>';
            html += '<code>hon/data/' + deviceInfo.mac_filename + '_programme1.json</code><br>';
            html += '<code>hon/data/' + deviceInfo.mac_filename + '_programme2.json</code><br>';
            html += '<code>...</code>';
            
            html += '<h4><i class="fas fa-list-alt"></i> {{Index général}}</h4>';
            html += '<code>hon/data/hon_devices_index.json</code>';
            html += '<p class="text-muted">{{Index de tous les appareils et programmes détectés.}}</p>';
            
            $('#jsonContent').html(html);
            $('#jsonModal').modal('show');
        }
    });
}

// ========================================
// FONCTIONS UTILITAIRES
// ========================================

// Gestion des erreurs
function handleError(error) {
    console.error('❌ Erreur AJAX:', error);
    $('#div_alert').showAlert({
        message: '{{Erreur de communication avec le serveur}} : ' + error,
        level: 'danger'
    });
}

// Formatage des durées
function formatDuration(seconds) {
    if (!seconds || seconds <= 0) return '0s';
    
    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var secs = seconds % 60;
    
    var result = '';
    if (hours > 0) result += hours + 'h ';
    if (minutes > 0) result += minutes + 'm ';
    if (secs > 0) result += secs + 's';
    
    return result.trim();
}

// Log avec préfixe
function logHon(message, data) {
    if (data) {
        console.log('🏠 hOn:', message, data);
    } else {
        console.log('🏠 hOn:', message);
    }
}

// ========================================
// FONCTIONS STANDARD JEEDOM
// ========================================

// Fonctions requises par le template Jeedom
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs">';
    tr += '<span class="cmdAttr" data-l1key="id"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<div class="input-group">';
    tr += '<input class="cmdAttr form-control" data-l1key="name" placeholder="{{Nom de la commande}}">';
    tr += '<span class="input-group-btn">';
    tr += '<a class="btn btn-default btn-sm cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a>';
    tr += '<a class="btn btn-default btn-sm cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    tr += '</span>';
    tr += '</div>';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i>';
    tr += '</td>';
    tr += '</tr>';
    
    $('#table_cmd tbody').append(tr);
    var tr = $('#table_cmd tbody tr').last();
    jeedom.eqLogic.buildSelectCmd({
        id: $('.eqLogic').attr('data-eqLogic_id'),
        filter: {type: 'info'},
        error: function(error) {
            $('#div_alert').showAlert({message: error.message, level: 'danger'});
        },
        success: function(result) {
            tr.find('.cmdAttr[data-l1key=value]').append(result);
            tr.setValues(_cmd, '.cmdAttr');
            jeedom.cmd.changeType(tr, init(_cmd.subType));
        }
    });
}

function printEqLogic(_eqLogic) {
    currentEqLogicId = _eqLogic.id;
    console.log('📋 Chargement équipement:', currentEqLogicId);
}

// Initialisation des fonctions globales
window.hon = {
    syncEquipments: syncHonEquipments,
    refreshTokens: refreshHonTokens,
    regenerateJsons: regenerateJsonFiles,
    refreshDevice: refreshDeviceInfo,
    showJsonFiles: showJsonFiles,
    log: logHon
};

console.log('✅ hon.js chargé et prêt');