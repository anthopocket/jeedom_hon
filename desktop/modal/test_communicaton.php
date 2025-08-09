<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>

<div id="div_testCommunicationAlert" style="display: none;"></div>

<div class="row">
    <div class="col-sm-12">
        <legend><i class="fas fa-wifi"></i> {{Test de communication hOn}}</legend>
        <div class="alert alert-info">
            {{Cette fonction va tester la communication avec le serveur hOn et afficher les logs détaillés.}}
        </div>
        
        <div class="form-group">
            <div class="col-sm-12 text-center">
                <a class="btn btn-primary" id="bt_startTest"><i class="fas fa-play"></i> {{Lancer le test}}</a>
                <a class="btn btn-warning" id="bt_clearLogs"><i class="fas fa-trash"></i> {{Vider les logs}}</a>
            </div>
        </div>
        
        <div id="div_testProgress" style="display: none;">
            <div class="progress">
                <div class="progress-bar progress-bar-striped active" role="progressbar" style="width: 100%">
                    {{Test en cours...}}
                </div>
            </div>
        </div>
        
        <div id="div_testResults">
            <legend><i class="fas fa-list"></i> {{Logs de communication}}</legend>
            <div class="form-group">
                <textarea id="ta_logs" class="form-control" rows="20" readonly style="font-family: monospace; font-size: 12px; background-color: #f8f9fa;"></textarea>
            </div>
            <div class="text-center">
                <a class="btn btn-info btn-sm" id="bt_refreshLogs"><i class="fas fa-sync"></i> {{Actualiser les logs}}</a>
            </div>
        </div>
    </div>
</div>

<script>
// Fonction pour actualiser les logs
function refreshLogs() {
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "getDetailedLogs"
        },
        dataType: 'json',
        global: false,
        success: function (data) {
            if (data.state == 'ok') {
                $('#ta_logs').val(data.result.join('\n'));
                $('#ta_logs').scrollTop($('#ta_logs')[0].scrollHeight);
            }
        }
    });
}

$('#bt_startTest').on('click', function () {
    $('#div_testProgress').show();
    $('#bt_startTest').prop('disabled', true);
    $('#ta_logs').val('Démarrage du test de communication...\n');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "testCommunication"
        },
        dataType: 'json',
        timeout: 30000,
        error: function (request, status, error) {
            $('#div_testProgress').hide();
            $('#bt_startTest').prop('disabled', false);
            $('#ta_logs').val($('#ta_logs').val() + 'ERREUR: ' + error + '\n');
            refreshLogs();
        },
        success: function (data) {
            $('#div_testProgress').hide();
            $('#bt_startTest').prop('disabled', false);
            
            if (data.state != 'ok') {
                $('#ta_logs').val($('#ta_logs').val() + 'ÉCHEC: ' + data.result + '\n');
            } else {
                $('#ta_logs').val($('#ta_logs').val() + 'SUCCÈS: ' + data.result + '\n');
            }
            
            // Actualiser les logs après le test
            setTimeout(refreshLogs, 1000);
        }
    });
});

$('#bt_refreshLogs').on('click', function () {
    refreshLogs();
});

$('#bt_clearLogs').on('click', function () {
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "clearLogs"
        },
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok') {
                $('#ta_logs').val('');
                $('#div_alert').showAlert({message: '{{Logs vidés}}', level: 'success'});
            }
        }
    });
});

// Actualiser les logs au chargement
$(document).ready(function() {
    refreshLogs();
    
    // Auto-refresh des logs toutes les 5 secondes
    setInterval(refreshLogs, 5000);
});
</script>#!/bin/bash
set -x

echo "Installation des dépendances Python pour hOn..."

# Installation des dépendances Python
pip3 install aiohttp

echo "Installation des dépendances terminée"