<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

// Déclaration des variables
$plugin = plugin::byId('hon');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

// Statistiques des équipements
$totalEquipments = count($eqLogics);
$activeEquipments = 0;
$programsCount = 0;

foreach ($eqLogics as $eqLogic) {
    if ($eqLogic->getIsEnable()) $activeEquipments++;
    $programsCount += (int)$eqLogic->getConfiguration('programsCount', 0);
}

// Vérifier l'état des tokens
$tokenStatus = [];
try {
    if (!empty($eqLogics)) {
        $tokenStatus = $eqLogics[0]->getTokenStatus();
    } else {
        $tempHon = new hon();
        $tokenStatus = $tempHon->getTokenStatus();
    }
} catch (Exception $e) {
    $tokenStatus = ['valid' => false, 'age' => 0, 'lastUpdate' => 'Jamais'];
}
?>

<div class="row row-overflow">
    <!-- Sidebar gauche avec liste des équipements -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <!-- Statut des tokens -->
        <div class="alert <?php echo $tokenStatus['valid'] ? 'alert-success' : 'alert-warning'; ?>" style="margin: 15px 0;">
            <div class="row">
                <div class="col-sm-8">
                    <i class="fas fa-key"></i> <strong>{{Statut des tokens}}</strong>
                    <br>
                    <small>
                        {{Dernière mise à jour}} : <?php echo $tokenStatus['lastUpdate'] ?? 'Jamais'; ?>
                        <?php if (isset($tokenStatus['age'])): ?>
                            ({{il y a}} <?php echo round($tokenStatus['age'] / 3600, 1); ?> {{heures}})
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-sm-4 text-right">
                    <?php if ($tokenStatus['valid']): ?>
                        <span class="label label-success">{{Valides}}</span>
                        <?php if (isset($tokenStatus['expireIn'])): ?>
                            <br><small>{{Expire dans}} <?php echo round($tokenStatus['expireIn'] / 3600, 1); ?>h</small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="label label-warning">{{Expirés}}</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <legend><i class="fas fa-table"></i> {{Mes équipements hOn}}</legend>
        
        <?php if ($totalEquipments == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i>
                <strong>{{Aucun équipement hOn configuré}}</strong>
                <br><br>
                <p>{{Assurez-vous d'avoir configuré vos identifiants dans la configuration du plugin.}}</p>
            </div>
        <?php else: ?>
            <!-- Statistiques simplifiées -->
            <div class="alert alert-info">
                <div class="row">
                    <div class="col-sm-6 text-center">
                        <h4 class="margin-0"><?php echo $totalEquipments; ?></h4>
                        <small>{{Équipements}}</small>
                    </div>
                    <div class="col-sm-6 text-center">
                        <h4 class="margin-0 <?php echo $activeEquipments > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo $activeEquipments; ?></h4>
                        <small>{{Actifs}}</small>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                $applianceType = $eqLogic->getConfiguration('applianceType', 'Unknown');
                $macAddress = $eqLogic->getConfiguration('macAddress', 'N/A');
                $programsCount = $eqLogic->getConfiguration('programsCount', '0');
                $lastSync = $eqLogic->getConfiguration('lastSync', 'Jamais');
                
                // Icône selon le type d'appareil
                $icon = 'fas fa-home';
                $deviceTypeLabel = 'Équipement hOn';
                $cardColor = '';
                
                if (strpos(strtolower($applianceType), 'laver') !== false || strpos(strtolower($applianceType), 'machine') !== false) {
                    $icon = 'fas fa-tshirt';
                    $deviceTypeLabel = 'Machine à laver';
                    $cardColor = 'border-left: 4px solid #3498db;';
                } elseif (strpos(strtolower($applianceType), 'séch') !== false || strpos(strtolower($applianceType), 'dry') !== false) {
                    $icon = 'fas fa-wind';
                    $deviceTypeLabel = 'Sèche-linge';
                    $cardColor = 'border-left: 4px solid #e74c3c;';
                } elseif (strpos(strtolower($applianceType), 'four') !== false) {
                    $icon = 'fas fa-fire';
                    $deviceTypeLabel = 'Four';
                    $cardColor = 'border-left: 4px solid #f39c12;';
                } elseif (strpos(strtolower($applianceType), 'lave-vaisselle') !== false) {
                    $icon = 'fas fa-utensils';
                    $deviceTypeLabel = 'Lave-vaisselle';
                    $cardColor = 'border-left: 4px solid #2ecc71;';
                }
                
                // Vérifier le statut de connexion
                $connectionStatus = 'unknown';
                $connectionCmd = $eqLogic->getCmd(null, 'connection_status');
                if (is_object($connectionCmd)) {
                    $connectionStatus = $connectionCmd->execCmd();
                }
                
                echo '<div class="eqLogicDisplayCard cursor '.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '" style="' . $cardColor . '">';
                echo '<img src="' . $eqLogic->getImage() . '"/>';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '<br>';
                echo '<span class="hiddenAsCard displayTableRight" style="font-size:0.85em;">';
                echo '<i class="' . $icon . '" title="' . $deviceTypeLabel . '"></i> ';
                echo $deviceTypeLabel;
                echo '<br>';
                echo '<i class="fas fa-ethernet" title="MAC: ' . $macAddress . '"></i> ';
                echo '<span title="' . $macAddress . '">' . substr($macAddress, -8) . '</span>';
                echo '<br>';
                echo '<i class="fas fa-list" title="Programmes disponibles"></i> ';
                echo $programsCount . ' {{programmes}}';
                echo '<br>';
                
                // Indicateur de statut de connexion
                switch ($connectionStatus) {
                    case 'connected':
                        echo '<span class="label label-success" title="Connecté"><i class="fas fa-wifi"></i> {{Connecté}}</span>';
                        break;
                    case 'disconnected':
                        echo '<span class="label label-warning" title="Déconnecté"><i class="fas fa-wifi"></i> {{Déconnecté}}</span>';
                        break;
                    default:
                        echo '<span class="label label-default" title="Statut inconnu"><i class="fas fa-question"></i> {{Inconnu}}</span>';
                        break;
                }
                
                echo '<br><small style="color: #7f8c8d;" title="Dernière synchronisation">';
                echo '<i class="fas fa-clock"></i> ' . $lastSync;
                echo '</small>';
                echo '</span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <!-- Panel de droite pour configuration d'un équipement -->
    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure">
                    <i class="fas fa-cogs"></i> {{Configuration avancée}}
                </a>
                <a class="btn btn-default btn-sm eqLogicAction" data-action="copy">
                    <i class="fas fa-copy"></i> {{Dupliquer}}
                </a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save">
                    <i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a>
                <a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove">
                    <i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Équipement}}
                </a>
            </li>
            <li role="presentation">
                <a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab">
                    <i class="fas fa-list-alt"></i> {{Commandes}}
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Onglet Équipement -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-8">
                                    <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        $options = '';
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            $options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                        }
                                        echo $options;
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-8">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline">';
                                        echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                                        echo '</label>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label"></label>
                                <div class="col-sm-8">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}
                                    </label>
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <legend><i class="fas fa-info-circle"></i> {{Informations hOn}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Type d'appareil}}</label>
                                <div class="col-sm-8">
                                    <span class="eqLogicAttr form-control-static" data-l1key="configuration" data-l2key="applianceType"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Adresse MAC}}</label>
                                <div class="col-sm-8">
                                    <span class="eqLogicAttr form-control-static" data-l1key="configuration" data-l2key="macAddress"></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de fichier MAC}}</label>
                                <div class="col-sm-8">
                                    <span class="eqLogicAttr form-control-static" data-l1key="configuration" data-l2key="mac_filename"></span>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <!-- Onglet Commandes -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                                <th style="min-width:200px;width:350px;">{{Nom}}</th>
                                <th style="width:130px;">{{Type}}</th>
                                <th style="min-width:200px;">{{Paramètres}}</th>
                                <th style="min-width:80px;width:200px;">{{Options}}</th>
                                <th style="min-width:80px;width:200px;">{{Action}}</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'hon', 'js', 'hon'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>