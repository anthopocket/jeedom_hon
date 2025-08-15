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
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-key"></i> {{Authentification hOn}}</legend>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Email hOn}}</label>
            <div class="col-lg-4">
                <input class="configKey form-control" data-l1key="email" placeholder="{{Votre email hOn}}"/>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Email utilisé pour l'application hOn}}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Mot de passe hOn}}</label>
            <div class="col-lg-4">
                <input type="password" class="configKey form-control" data-l1key="password" placeholder="{{Votre mot de passe hOn}}"/>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Mot de passe utilisé pour l'application hOn}}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label"></label>
            <div class="col-lg-4">
                <a class="btn btn-success" id="bt_testConnection">
                    <i class="fas fa-check"></i> {{Tester la connexion}}
                </a>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Vérifie la validité des identifiants}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-cogs"></i> {{Configuration avancée}}</legend>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Fréquence de rafraîchissement}}</label>
            <div class="col-lg-4">
                <select class="configKey form-control" data-l1key="refreshInterval">
                    <option value="60">{{1 minute}}</option>
                    <option value="120">{{2 minutes}}</option>
                    <option value="300" selected>{{5 minutes}}</option>
                    <option value="600">{{10 minutes}}</option>
                    <option value="900">{{15 minutes}}</option>
                </select>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Intervalle de mise à jour des données}}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Timeout des commandes}}</label>
            <div class="col-lg-4">
                <input class="configKey form-control" data-l1key="commandTimeout" placeholder="30" type="number" min="10" max="120"/>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Timeout en secondes pour les commandes (défaut: 30s)}}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Mode debug}}</label>
            <div class="col-lg-4">
                <input type="checkbox" class="configKey" data-l1key="debug"/>
            </div>
            <div class="col-lg-4">
                <span class="help-block">{{Active les logs de débogage détaillés}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-info-circle"></i> {{Informations}}</legend>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Statut des tokens}}</label>
            <div class="col-lg-4">
                <span id="tokenStatus" class="label label-default">{{Inconnu}}</span>
            </div>
            <div class="col-lg-4">
                <a class="btn btn-warning btn-sm" id="bt_refreshTokens">
                    <i class="fas fa-sync"></i> {{Actualiser les tokens}}
                </a>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Dernière synchronisation}}</label>
            <div class="col-lg-4">
                <span id="lastSync" class="form-control-static">{{Jamais}}</span>
            </div>
            <div class="col-lg-4">
                <a class="btn btn-primary btn-sm" id="bt_syncDevices">
                    <i class="fas fa-sync"></i> {{Synchroniser maintenant}}
                </a>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Dossier de données}}</label>
            <div class="col-lg-8">
                <code>plugins/hon/data/</code>
                <span class="help-block">{{Contient les fichiers JSON des équipements et programmes}}</span>
            </div>
        </div>
    </fieldset>
</form>

<script>
// Test de connexion
$('#bt_testConnection').off('click').on('click', function() {
    var email = $('.configKey[data-l1key="email"]').val();
    var password = $('.configKey[data-l1key="password"]').val();
    
    if (!email || !password) {
        $('#div_alert').showAlert({message: '{{Veuillez saisir votre email et mot de passe}}', level: 'warning'});
        return;
    }
    
    $('#bt_testConnection').html('<i class="fas fa-spinner fa-spin"></i> {{Test en cours...}}');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'testConnection',
            email: email,
            password: password
        },
        dataType: 'json',
        error: function(request, status, error) {
            $('#div_alert').showAlert({message: '{{Erreur}} : ' + error, level: 'danger'});
            $('#bt_testConnection').html('<i class="fas fa-check"></i> {{Tester la connexion}}');
        },
        success: function(data) {
            $('#bt_testConnection').html('<i class="fas fa-check"></i> {{Tester la connexion}}');
            
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            updateTokenStatus();
        }
    });
});

// Actualiser les tokens
$('#bt_refreshTokens').off('click').on('click', function() {
    $('#bt_refreshTokens').html('<i class="fas fa-spinner fa-spin"></i> {{Actualisation...}}');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'refreshTokens'
        },
        dataType: 'json',
        error: function(request, status, error) {
            $('#div_alert').showAlert({message: '{{Erreur}} : ' + error, level: 'danger'});
            $('#bt_refreshTokens').html('<i class="fas fa-sync"></i> {{Actualiser les tokens}}');
        },
        success: function(data) {
            $('#bt_refreshTokens').html('<i class="fas fa-sync"></i> {{Actualiser les tokens}}');
            
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            updateTokenStatus();
        }
    });
});

// Synchroniser les équipements
$('#bt_syncDevices').off('click').on('click', function() {
    $('#bt_syncDevices').html('<i class="fas fa-spinner fa-spin"></i> {{Synchronisation...}}');
    
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'syncDevices'
        },
        dataType: 'json',
        error: function(request, status, error) {
            $('#div_alert').showAlert({message: '{{Erreur}} : ' + error, level: 'danger'});
            $('#bt_syncDevices').html('<i class="fas fa-sync"></i> {{Synchroniser maintenant}}');
        },
        success: function(data) {
            $('#bt_syncDevices').html('<i class="fas fa-sync"></i> {{Synchroniser maintenant}}');
            
            if (data.state != 'ok') {
                $('#div_alert').showAlert({message: data.result, level: 'danger'});
                return;
            }
            
            $('#div_alert').showAlert({message: data.result, level: 'success'});
            $('#lastSync').text(new Date().toLocaleString());
        }
    });
});

// Mise à jour du statut des tokens
function updateTokenStatus() {
    $.ajax({
        type: 'POST',
        url: 'plugins/hon/core/ajax/hon.ajax.php',
        data: {
            action: 'getTokenStatus'
        },
        dataType: 'json',
        success: function(data) {
            if (data.state == 'ok') {
                var status = data.result;
                var labelClass = status.valid ? 'label-success' : 'label-warning';
                var labelText = status.valid ? '{{Valides}}' : '{{Expirés}}';
                
                $('#tokenStatus').removeClass('label-default label-success label-warning')
                    .addClass(labelClass).text(labelText);
            }
        }
    });
}

// Mettre à jour le statut au chargement
$(document).ready(function() {
    updateTokenStatus();
});
</script>