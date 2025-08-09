<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('hon');
?>

<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-key"></i> {{Identifiants hOn}}</legend>
        
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Email hOn}}</label>
            <div class="col-lg-4">
                <input class="configKey form-control" data-l1key="email" placeholder="{{Votre email hOn}}" />
                <span class="help-block">{{Email utilisé pour votre compte hOn}}</span>
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Mot de passe hOn}}</label>
            <div class="col-lg-4">
                <input type="password" class="configKey form-control" data-l1key="password" placeholder="{{Votre mot de passe hOn}}" />
                <span class="help-block">{{Mot de passe de votre compte hOn}}</span>
            </div>
        </div>
    </fieldset>
</form>

<div class="form-group">
    <label class="col-lg-3 control-label">{{Test de connexion}}</label>
    <div class="col-lg-4">
        <a class="btn btn-primary" id="bt_testConnection">
            <i class="fas fa-plug"></i> {{Tester la connexion}}
        </a>
        <span id="testConnectionResult" style="margin-left: 10px;"></span>
    </div>
</div>

<div class="form-group">
    <label class="col-lg-3 control-label">{{Synchronisation}}</label>
    <div class="col-lg-4">
        <a class="btn btn-success" id="bt_syncDevices">
            <i class="fas fa-sync"></i> {{Synchroniser les équipements}}
        </a>
        <span id="syncResult" style="margin-left: 10px;"></span>
    </div>
</div>

<div class="form-group">
    <label class="col-lg-3 control-label">{{Statut des tokens}}</label>
    <div class="col-lg-6">
        <div id="tokenStatus" class="alert alert-info">
            <i class="fas fa-info-circle"></i> {{Cliquez sur "Statut tokens" pour voir les informations}}
        </div>
        <a class="btn btn-info btn-sm" id="bt_tokenStatus">
            <i class="fas fa-info"></i> {{Statut tokens}}
        </a>
        <a class="btn btn-warning btn-sm" id="bt_refreshTokens">
            <i class="fas fa-refresh"></i> {{Forcer régénération}}
        </a>
    </div>
</div>

<script>
// Test de connexion
$('#bt_testConnection').on('click', function () {
    var email = $('.configKey[data-l1key="email"]').val();
    var password = $('.configKey[data-l1key="password"]').val();
    
    if (!email || !password) {
        $('#testConnectionResult').empty().append('<span class="label label-warning">{{Veuillez saisir email et mot de passe}}</span>');
        return;
    }
    
    $('#testConnectionResult').empty().append('<i class="fa fa-spinner fa-spin"></i> {{Test en cours...}}');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "testConnection",
            email: email,
            password: password
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#testConnectionResult').empty().append('<span class="label label-danger">{{Erreur de connexion}}</span>');
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#testConnectionResult').empty().append('<span class="label label-danger">{{Erreur : }}' + data.result + '</span>');
                return;
            }
            $('#testConnectionResult').empty().append('<span class="label label-success">{{Connexion réussie}}</span>');
        }
    });
});

// Synchronisation des équipements
$('#bt_syncDevices').on('click', function () {
    $('#syncResult').empty().append('<i class="fa fa-spinner fa-spin"></i> {{Synchronisation en cours...}}');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "syncDevices"
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#syncResult').empty().append('<span class="label label-danger">{{Erreur de synchronisation}}</span>');
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#syncResult').empty().append('<span class="label label-danger">{{Erreur : }}' + data.result + '</span>');
                return;
            }
            $('#syncResult').empty().append('<span class="label label-success">{{Synchronisation réussie}}</span>');
            
            // Recharger la page après un délai
            setTimeout(function() {
                window.location.reload();
            }, 2000);
        }
    });
});

// Statut des tokens
$('#bt_tokenStatus').on('click', function () {
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "getTokenStatus"
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#tokenStatus').html('<i class="fas fa-times-circle"></i> {{Erreur lors de la récupération du statut}}').removeClass().addClass('alert alert-danger');
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#tokenStatus').html('<i class="fas fa-times-circle"></i> {{Erreur : }}' + data.result).removeClass().addClass('alert alert-danger');
                return;
            }
            
            var status = data.result;
            var alertClass = status.valid ? 'alert-success' : 'alert-warning';
            var icon = status.valid ? 'fas fa-check-circle' : 'fas fa-clock';
            
            var html = '<i class="' + icon + '"></i> ';
            html += '<strong>{{Dernière mise à jour}}</strong> : ' + status.lastUpdate + '<br>';
            html += '<strong>{{Âge des tokens}}</strong> : ' + status.ageFormatted + '<br>';
            
            if (status.valid) {
                html += '<strong>{{Expire dans}}</strong> : ' + status.expireInFormatted;
            } else {
                html += '<strong>{{Statut}}</strong> : <span class="text-danger">{{Expirés}}</span>';
            }
            
            $('#tokenStatus').html(html).removeClass().addClass('alert ' + alertClass);
        }
    });
});

// Forcer la régénération des tokens
$('#bt_refreshTokens').on('click', function () {
    if (!confirm('{{Êtes-vous sûr de vouloir forcer la régénération des tokens ?}}')) {
        return;
    }
    
    $(this).html('<i class="fa fa-spinner fa-spin"></i> {{Régénération...}}').prop('disabled', true);
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "refreshTokens"
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#bt_refreshTokens').html('<i class="fas fa-refresh"></i> {{Forcer régénération}}').prop('disabled', false);
        },
        success: function (data) {
            $('#bt_refreshTokens').html('<i class="fas fa-refresh"></i> {{Forcer régénération}}').prop('disabled', false);
            
            if (data.state != 'ok') {
                $('#tokenStatus').html('<i class="fas fa-times-circle"></i> {{Erreur : }}' + data.result).removeClass().addClass('alert alert-danger');
                return;
            }
            
            $('#tokenStatus').html('<i class="fas fa-check-circle"></i> {{Tokens régénérés avec succès}}').removeClass().addClass('alert alert-success');
            
            // Actualiser le statut automatiquement
            setTimeout(function() {
                $('#bt_tokenStatus').click();
            }, 1000);
        }
    });
});
</script>