<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('energyManager');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/** Champ "sélecteur de commande" d'un équipement. */
function em_eq_cmd($key, $label, $type = 'info', $help = '') {
	echo '<div class="form-group emField" data-key="' . $key . '">';
	echo '<label class="col-sm-4 control-label">' . $label;
	if ($help !== '') {
		echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
	}
	echo '</label><div class="col-sm-6"><div class="input-group">';
	echo '<input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="' . $key . '"/>';
	echo '<span class="input-group-btn"><a class="btn btn-default roundedRight emEqCmdPick" data-type="' . $type . '"><i class="fas fa-list-alt"></i></a></span>';
	echo '</div></div></div>';
}

/** Champ "valeur" d'un équipement. */
function em_eq_val($key, $label, $default, $unit = '', $help = '') {
	echo '<div class="form-group emField" data-key="' . $key . '">';
	echo '<label class="col-sm-4 control-label">' . $label;
	if ($help !== '') {
		echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
	}
	echo '</label><div class="col-sm-3">';
	echo '<input type="number" step="any" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="' . $key . '" placeholder="' . $default . '"/>';
	echo '</div>';
	if ($unit !== '') {
		echo '<div class="col-sm-1" style="padding-top:7px">' . $unit . '</div>';
	}
	echo '</div>';
}
?>

<div class="row row-overflow">
	<!-- Liste des équipements -->
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
			<div class="cursor logoSecondary" id="bt_emSimulator">
				<i class="fas fa-flask"></i>
				<br>
				<span>{{Simulateur}}</span>
			</div>
		</div>
		<legend><i class="fas fa-bolt"></i> {{Mes équipements}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement Energy Manager n\'est encore créé, cliquez sur « Ajouter » pour commencer.}}</div>';
		} else {
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
			echo '<div class="input-group-btn"><a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a></div>';
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
	</div>

	<!-- Page de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br/>
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-7">
							<legend><i class="fas fa-wrench"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;"/>
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
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
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Type d'équipement}}</label>
								<div class="col-sm-6">
									<select id="sel_emType" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="eqType">
										<option value="simple">{{Consommateur simple (radiateur)}}</option>
										<option value="ecs">{{Ballon d'eau chaude}}</option>
										<option value="tesla">{{Tesla (teslasmartcharge)}}</option>
										<option value="clim">{{Climatisation tri-split (à venir)}}</option>
										<option value="manager">{{Gestionnaire (widget powerFlow)}}</option>
									</select>
								</div>
							</div>

							<!-- Consommateur simple / ballon : pilotage et mesure -->
							<div class="emType emType-simple emType-ecs">
								<legend><i class="fas fa-plug"></i> {{Pilotage et mesure}}</legend>
								<?php em_eq_cmd('cmd_on', '{{Commande ON}}', 'action'); ?>
								<?php em_eq_cmd('cmd_off', '{{Commande OFF}}', 'action'); ?>
								<?php em_eq_cmd('cmd_state', '{{État ON/OFF (optionnel)}}', 'info', '{{Sinon, l\'état est déduit de la puissance mesurée.}}'); ?>
								<?php em_eq_cmd('cmd_power', '{{Puissance réelle mesurée (Shelly)}}'); ?>
								<?php em_eq_val('power_theo', '{{Puissance théorique}}', '1000', 'W', '{{Modifiable à tout moment.}}'); ?>
							</div>

							<!-- Consommateur simple -->
							<div class="emType emType-simple">
								<legend><i class="fas fa-thermometer-half"></i> {{Pièce}}</legend>
								<?php em_eq_cmd('cmd_temp', '{{Sonde de température de la pièce}}'); ?>
								<?php em_eq_cmd('cmd_window', '{{Capteur d\'ouverture (1 = ouvert)}}'); ?>
								<?php em_eq_val('target', '{{Consigne de température}}', '20', '°C'); ?>
								<?php em_eq_val('boost', '{{Dépassement autorisé avec le solaire}}', '2', '°C', '{{On peut dépasser la consigne pour stocker la chaleur du soleil et économiser le soir.}}'); ?>
								<div class="form-group emField" data-key="priority">
									<label class="col-sm-4 control-label">{{Priorité de la pièce}}</label>
									<div class="col-sm-3">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="priority">
											<option value="1">1 - {{à prioriser}}</option>
											<option value="2" selected>2</option>
											<option value="3">3 - {{moins prioritaire}}</option>
										</select>
									</div>
								</div>
							</div>

							<!-- Ballon -->
							<div class="emType emType-ecs">
								<legend><i class="fas fa-tint"></i> {{Ballon d'eau chaude}}</legend>
								<?php em_eq_cmd('cmd_water_temp', '{{Température de l\'eau}}'); ?>
								<?php em_eq_val('min_temp', '{{Température minimale garantie}}', '50', '°C', '{{En dessous, le ballon est (re)chauffé avant l\'heure limite. Pas de maximum : le thermostat interne coupe seul.}}'); ?>
								<?php em_eq_val('zero_threshold', '{{Seuil de consommation nulle}}', '20', 'W'); ?>
								<?php em_eq_val('zero_delay', '{{Délai avant de conclure « thermostat atteint »}}', '120', 's'); ?>
								<div class="form-group emField" data-key="cut_on_zero">
									<label class="col-sm-4 control-label">{{Couper l'équipement quand la consommation retombe à zéro}}</label>
									<div class="col-sm-3"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="cut_on_zero" checked/></div>
								</div>
							</div>

							<!-- Tesla -->
							<div class="emType emType-tesla">
								<legend><i class="fas fa-car"></i> {{Tesla via teslasmartcharge}}</legend>
								<?php em_eq_cmd('cmd_set_mode', '{{teslasmartcharge : commande « Définir Mode » (set_mode)}}', 'action', '{{energyManager envoie Solaire, Stand-By ou Non actif.}}'); ?>
								<?php em_eq_cmd('cmd_mode_info', '{{teslasmartcharge : info « Mode Actuel » (mode_actuel)}}', 'info', '{{teslasmartcharge change de mode seul (voiture débranchée, cible atteinte) : energyManager le lit ici.}}'); ?>
								<?php em_eq_cmd('cmd_set_priority', '{{teslasmartcharge : commande « Définir Priorité » (set_priorite_charge)}}', 'action', '{{energyManager envoie voiture ou maison selon son sélecteur de priorité.}}'); ?>
								<?php em_eq_cmd('cmd_set_threshold', '{{teslasmartcharge : commande « Définir Seuil Bat Maison » (facultatif)}}', 'action', '{{Détectée automatiquement (set_seuil_batterie_maison).}}'); ?>
								<div class="form-group emField" data-key="aggressiveness">
									<label class="col-sm-4 control-label">{{Agressivité}} <sup><i class="fas fa-question-circle tooltips" title="{{Puissance libérée à la fois sur la limite des micro-onduleurs une fois la charge en cours. Avant, seuls 500 W sont libérés pour déclencher la charge.}}"></i></sup></label>
									<div class="col-sm-4">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="aggressiveness">
											<option value="small">{{Petite : paliers de 500 W}}</option>
											<option value="medium" selected>{{Moyenne : paliers de 1000 W}}</option>
											<option value="large">{{Grande : paliers de 2000 W}}</option>
										</select>
									</div>
								</div>
								<?php em_eq_cmd('cmd_margin', '{{Marge EDF de teslasmartcharge (facultatif)}}', 'info', '{{Détectée automatiquement (marge_actuelle). Une marge négative autorise un soutirage EDF que energyManager accepte sans réagir.}}'); ?>
								<?php em_eq_cmd('cmd_plugged', '{{Voiture branchée (facultatif)}}', 'info', '{{Détecté automatiquement depuis le plugin Tesla (charge_port_latch). À renseigner seulement si la détection échoue.}}'); ?>
								<?php em_eq_cmd('tesla_cmd_power', '{{Puissance réelle de charge}}'); ?>
								<?php em_eq_val('tesla_power_max', '{{Puissance maximale}}', '7200', 'W', '{{32 A x 230 V environ. La Tesla est considérée comme pleine au-delà de ce seuil moins 500 W.}}'); ?>
							</div>

							<div class="emType emType-clim">
								<div class="alert alert-info">{{La climatisation tri-split (3 chambres) est en attente : elle sera ajoutée dans une prochaine version.}}</div>
							</div>
						</div>

						<div class="col-lg-5">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Description}}</label>
								<div class="col-sm-7">
									<textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea>
								</div>
							</div>
						</div>
					</fieldset>
				</form>
			</div>

			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br/>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th>{{Type}}</th>
								<th style="min-width:120px;width:200px;">{{Options}}</th>
								<th style="min-width:80px;width:140px;">{{Actions}}</th>
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

<?php include_file('desktop', 'energyManager', 'js', 'energyManager'); ?>
<?php include_file('desktop', 'energyManager', 'css', 'energyManager'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
