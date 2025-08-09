<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

// Déclaration des variables
$plugin = plugin::byId('hon');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <!-- Sidebar gauche avec liste des équipements -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
            <div class="cursor bt_syncHonEquipments logoSecondary">
                <i class="fas fa-sync"></i>
                <br>
                <span>{{Synchroniser}}</span>
            </div>
        </div>

        <legend><i class="fas fa-table"></i> {{Mes équipements hOn}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;color:orange;">{{Aucun équipement hOn configuré}}</div>';
        } else {
            // Calcul des statistiques
            $totalEquipments = count($eqLogics);
            $activeEquipments = 0;
            $onlineEquipments = 0;
            
            foreach ($eqLogics as $eqLogic) {
                if ($eqLogic->getIsEnable()) $activeEquipments++;
                // Vérifier le statut en ligne (à implémenter selon votre logique)
                $onlineCmd = $eqLogic->getCmd(null, 'onOffStatus');
                if (is_object($onlineCmd) && $onlineCmd->execCmd() == 1) {
                    $onlineEquipments++;
                }
            }
            
            echo '<div class="alert alert-info">';
            echo '<i class="fas fa-info-circle"></i> ';
            echo '<strong>{{Statistiques}}</strong> : ';
            echo $totalEquipments . ' {{équipements}} | ';
            echo $activeEquipments . ' {{actifs}} | ';
            echo $onlineEquipments . ' {{en ligne}}';
            echo '</div>';
        }
        ?>
        
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                $honType = $eqLogic->getConfiguration('applianceTypeName', 'Unknown');
                $macAddress = $eqLogic->getConfiguration('macAddress', 'N/A');
                $deviceId = $eqLogic->getConfiguration('deviceId', 'N/A');
                $brand = $eqLogic->getConfiguration('brand', 'hOn');
                
                // Icône selon le type d'appareil
                $icon = 'fas fa-question-circle';
                switch (strtoupper($honType)) {
                    case 'WASHING_MACHINE':
                    case 'WM':
                        $icon = 'fas fa-tshirt';
                        break;
                    case 'TUMBLE_DRYER':
                    case 'TD':
                        $icon = 'fas fa-wind';
                        break;
                }
                
                echo '<div class="eqLogicDisplayCard cursor '.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $eqLogic->getImage() . '"/>';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '<br>';
                echo '<span class="hiddenAsCard displayTableRight" style="font-size:0.85em;">';
                echo '<i class="' . $icon . '" title="' . $honType . '"></i> ';
                echo $brand . ' | ';
                echo '<span title="MAC: ' . $macAddress . '">MAC: ' . substr($macAddress, -8) . '</span>';
                if ($deviceId !== 'N/A') {
                    echo '<br><span style="font-size:0.75em;" title="Device ID: ' . $deviceId . '">ID: ' . substr($deviceId, -8) . '</span>';
                }
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
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="applianceTypeName" placeholder="{{Type d'appareil}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Adresse MAC}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="macAddress" placeholder="{{Adresse MAC}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Device ID}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="deviceId" placeholder="{{Device ID}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Marque}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="brand" placeholder="{{Marque}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Modèle}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="modelName" placeholder="{{Modèle}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Version firmware}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="fwVersion" placeholder="{{Version firmware}}" readonly>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Dernière synchronisation}}</label>
                                <div class="col-sm-8">
                                    <span class="form-control-static" id="lastSync">{{Jamais}}</span>
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