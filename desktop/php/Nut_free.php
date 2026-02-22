<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('Nut_free');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">

    <!-- Page d'accueil du plugin -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <div class="row">
            <div class="col-sm-10">
                <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
                <div class="eqLogicThumbnailContainer">
                    <div class="cursor eqLogicAction logoPrimary" data-action="add">
                        <i class="fas fa-plus-circle"></i>
                        <br/>
                        <span style="color:var(--txt-color)">{{Ajouter}}</span>
                    </div>
                    <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                        <i class="fas fa-wrench"></i>
                        <br/>
                        <span style="color:var(--txt-color)">{{Configuration}}</span>
                    </div>
                    <div class="cursor pluginAction logoSecondary" data-action="openLocation" data-location="<?= $plugin->getDocumentation() ?>">
                        <i class="fas fa-book icon_blue"></i>
                        <br/>
                        <span style="color:var(--txt-color)">{{Documentation}}</span>
                    </div>
                    <div class="cursor pluginAction logoSecondary" data-action="openLocation" data-location="https://community.jeedom.com/tag/plugin-<?= $plugin->getId() ?>">
                        <i class="fas fa-thumbs-up icon_green"></i>
                        <br/>
                        <span style="color:var(--txt-color)">{{Community}}</span>
                    </div>
                    <div class="cursor eqLogicAction logoSecondary" data-action="createCommunityPost">
                        <i class="fas fa-ambulance icon_blue"></i>
                        <br/>
                        <span style="color:var(--txt-color)">{{Post Community}}</span>
                    </div>
                </div>
            </div>
        </div>
        <legend><i class="fas fa-plug"></i> {{Mes NUT Free}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement NUT Free trouvé, cliquer sur "Ajouter" pour commencer}}</div>';
        } else {
            echo '<div class="input-group" style="margin:5px;">';
            echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
            echo '<div class="input-group-btn">';
            echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
            echo '</div>';
            echo '</div>';
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $plugin->getPathImgIcon() . '"/>';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div><!-- /.eqLogicThumbnailDisplay -->

    <!-- Page de configuration de l'équipement -->
    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-plug"></i> {{Equipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
        </ul>
        <div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">

            <!-- Onglet de configuration de l'équipement -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br/>
                <div class="row">
                    <div class="col-sm-6">
                        <form class="form-horizontal" autocomplete="off">
                            <fieldset>
                                <legend><i class="fas fa-arrow-circle-left eqLogicAction cursor" data-action="returnToThumbnailDisplay"></i> {{Général}}</legend>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                    <div class="col-sm-6">
                                        <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;"/>
                                        <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement NUT Free}}"/>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                    <div class="col-sm-6">
                                        <select class="form-control eqLogicAttr" data-l1key="object_id">
                                            <option value="">{{Aucun}}</option>
                                            <?php
                                            foreach ((jeeObject::buildTree(null, false)) as $object) {
                                                echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                            }
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
                                        <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
                                        <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
                                    </div>
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend>{{Connexion NUT}}</legend>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Protocole de connexion}}</label>
                                    <div class="col-sm-6">
                                        <select id="selConnexionMode" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="connexionMode">
                                            <option value="nut" selected>{{NUT (protocole TCP direct)}}</option>
                                            <option value="ssh">{{SSH (via SSH-Manager)}}</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Mode NUT (protocole TCP direct) -->
                                <div class="nut-protocol">
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label">{{Adresse IP du serveur NUT}}
                                            <sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP ou hostname de la machine hébergeant le serveur NUT}}"></i></sup>
                                        </label>
                                        <div class="col-sm-6">
                                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="addressIp" type="text" placeholder="{{ex: 192.168.1.100}}"/>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label">{{Port NUT}}
                                            <sup><i class="fas fa-question-circle tooltips" title="{{Port TCP du serveur NUT (défaut : 3493)}}"></i></sup>
                                        </label>
                                        <div class="col-sm-3">
                                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nutPort" type="number" min="1" max="65535" placeholder="3493"/>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label">{{Login NUT}}
                                            <sup><i class="fas fa-question-circle tooltips" title="{{Login d'authentification upsd (optionnel, laisser vide si le serveur NUT ne l'exige pas)}}"></i></sup>
                                        </label>
                                        <div class="col-sm-6">
                                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nutLogin" type="text" placeholder="{{optionnel}}" autocomplete="off"/>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label">{{Mot de passe NUT}}
                                            <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe upsd (optionnel, laisser vide si le serveur NUT ne l'exige pas)}}"></i></sup>
                                        </label>
                                        <div class="col-sm-6">
                                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nutPassword" type="password" placeholder="{{optionnel}}" autocomplete="new-password"/>
                                        </div>
                                    </div>
                                </div>
                                <!-- Mode SSH (via SSH-Manager) -->
                                <div class="nut-ssh" style="display:none;">
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label help" data-help="{{Choisir un hôte SSH dans la liste ou en créer un nouveau}}">{{Hôte SSH}}</label>
                                        <div class="col-sm-6">
                                            <div class="input-group">
                                                <select class="eqLogicAttr form-control roundedLeft sshmanagerHelper" data-helper="list" data-l1key="configuration" data-l2key="SSHHostId">
                                                </select>
                                                <span class="input-group-btn">
                                                    <a class="btn btn-default cursor roundedRight sshmanagerHelper" data-helper="add" title="{{Ajouter un nouvel hôte SSH}}">
                                                        <i class="fas fa-plus-circle"></i>
                                                    </a>
                                                    <a class="btn btn-default cursor roundedRight sshmanagerHelper" data-helper="edit" title="{{Editer cet hôte SSH}}" style="display:none;">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </a>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Commun NUT + SSH -->
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Auto-détection UPS ?}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Si activé, le nom de l'UPS sera détecté automatiquement via upsc -l}}"></i></sup>
                                    </label>
                                    <div class="col-sm-6">
                                        <select id="selUpsAuto" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="upsAutoSelect">
                                            <option value="0" selected>{{Oui (automatique)}}</option>
                                            <option value="1">{{Non (manuel)}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="nut-ups-manual" style="display:none;">
                                    <div class="form-group">
                                        <label class="col-sm-4 control-label">{{Nom de l'UPS}}
                                            <sup><i class="fas fa-question-circle tooltips" title="{{Nom retourné par la commande upsc -l sur le serveur NUT}}"></i></sup>
                                        </label>
                                        <div class="col-sm-6">
                                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ups" type="text" placeholder="{{ex: myups}}"/>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div><!-- /.tab-pane #eqlogictab -->

            <!-- Onglet des commandes -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br/><br/>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">{{Id}}</th>
                                <th style="min-width:200px;width:280px;">{{Nom}}</th>
                                <th style="min-width:250px;">{{Commande}}</th>
                                <th style="min-width:50px;width:70px;">{{Unité}}</th>
                                <th style="min-width:150px;width:180px;">{{Options}}</th>
                                <th style="min-width:150px;">{{État}}</th>
                                <th style="min-width:130px;width:150px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div><!-- /.tab-pane #commandtab -->

        </div><!-- /.tab-content -->
    </div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<!-- Inclusion du helper SSH-Manager -->
<?php
if (class_exists('sshmanager')) {
    include_file('desktop', 'sshmanager.helper', 'js', 'sshmanager');
} else {
    log::add('Nut_free', 'error', '[PLUGIN] Impossible de charger sshmanager.helper.js (vérifiez les dépendances)');
}
?>
<?php include_file('desktop', 'Nut_free', 'js', 'Nut_free'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>

