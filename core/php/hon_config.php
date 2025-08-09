<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('hon');
?>

<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Email hOn}}</label>
            <div class="col-lg-4">
                <input class="configKey form-control" data-l1key="email" placeholder="{{Votre email hOn}}" />
            </div>
        </div>
        
        <div class="form-group">
            <label class="col-lg-3 control-label">{{Mot de passe hOn}}</label>
            <div class="col-lg-4">
                <input type="password" class="configKey form-control" data-l1key="password" placeholder="{{Votre mot de passe hOn}}" />
            </div>
        </div>

        <div class="form-group">
            <label class="col-lg-3 control-label">{{Fréquence de synchronisation (minutes)}}</label>
            <div class="col-lg-2">
                <input class="configKey form-control" data-l1key="sync_frequency" placeholder="1" />
                <span class="help-block">{{Fréquence de synchronisation des données (défaut: 1 minute)}}</span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-lg-3 control-label">{{Niveau de log}}</label>
            <div class="col-lg-2">
                <select class="configKey form-control" data-l1key="log_level">
                    <option value="error">{{Erreur}}</option>
                    <option value="warning">{{Attention}}</option>
                    <option value="info" selected>{{Info}}</option>
                    <option value="debug">{{Debug}}</option>
                </select>
            </div>
        </div>
    </fieldset>
</form>

<div class="form-group">
    <label class="col-lg-3 control-label">{{Test de connexion}}</label>
    <div class="col-lg-4">
        <a class="btn btn-primary" id="bt_testConnection">{{Tester la connexion}}</a>
        <span id="testConnectionResult"></span>
    </div>
</div>

<div class="form-group">
    <label class="col-lg-3 control-label">{{Dépendances Python}}</label>
    <div class="col-lg-4">
        <a class="btn btn-info" id="bt_testPython">{{Test Python}}</a>
        <a class="btn btn-warning" id="bt_installDependencies">{{Installer les dépendances}}</a>
        <span id="dependenciesResult"></span>
    </div>
</div>

<script>
$('#bt_testConnection').on('click', function () {
    $('#testConnectionResult').empty().append('<i class="fa fa-spinner fa-spin"></i> {{Test en cours...}}');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "testConnection",
            email: $('.configKey[data-l1key="email"]').val(),
            password: $('.configKey[data-l1key="password"]').val()
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

$('#bt_installDependencies').on('click', function () {
    $('#dependenciesResult').empty().append('<i class="fa fa-spinner fa-spin"></i> {{Installation en cours...}}');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "installDependencies"
        },
        dataType: 'json',
        timeout: 60000, // 1 minute
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#dependenciesResult').empty().append('<span class="label label-danger">{{Erreur d\'installation}}</span>');
        },
        success: function (data) {
            if (data.state != 'ok') {
                $('#dependenciesResult').empty().append('<span class="label label-danger">{{Erreur : }}' + data.result + '</span>');
                return;
            }
            $('#dependenciesResult').empty().append('<span class="label label-success">{{Dépendances installées}}</span>');
        }
    });
});

$('#bt_testPython').on('click', function () {
    $('#dependenciesResult').empty().append('<i class="fa fa-spinner fa-spin"></i> {{Test en cours...}}');
    
    $.ajax({
        type: "POST",
        url: "plugins/hon/core/ajax/hon.ajax.php",
        data: {
            action: "testPython"
        },
        dataType: 'json',
        success: function (data) {
            if (data.state == 'ok') {
                // Afficher le résultat dans une popup
                $('#md_modal').dialog({title: "{{Résultat du test Python}}"});
                $('#md_modal').html('<pre style="max-height: 400px; overflow-y: auto;">' + data.result + '</pre>');
                $('#md_modal').dialog('open');
                $('#dependenciesResult').empty().append('<span class="label label-info">{{Test terminé}}</span>');
            } else {
                $('#dependenciesResult').empty().append('<span class="label label-danger">{{Erreur test}}</span>');
            }
        }
    });
});
</script>