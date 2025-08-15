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

// Actualisation d'un équipement individuel
$(document).off('click', '.bt_refreshDevice').on('click', '.bt_refreshDevice', function() {
    var eqLogicId = $('.eqLogic').attr('data-eqLogic_id');
    if (eqLogicId) {
        console.log('🔄 Rafraîchissement équipement ID:', eqLogicId);
        refreshDeviceInfo(eqLogicId);
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

console.log('✅ hon.js chargé et prêt');