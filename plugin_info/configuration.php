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

/** Ligne "sélecteur de commande". $type : info | action. */
function em_cmd_row($key, $label, $type = 'info', $help = '') {
	echo '<div class="form-group">';
	echo '<label class="col-md-4 control-label">' . $label;
	if ($help !== '') {
		echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
	}
	echo '</label>';
	echo '<div class="col-md-6"><div class="input-group">';
	echo '<input class="configKey form-control roundedLeft" data-l1key="' . $key . '"/>';
	echo '<span class="input-group-btn"><a class="btn btn-default roundedRight emCmdPick" data-type="' . $type . '"><i class="fas fa-list-alt"></i></a></span>';
	echo '</div></div></div>';
}

/** Ligne "valeur simple". */
function em_val_row($key, $label, $default, $help = '', $unit = '') {
	echo '<div class="form-group">';
	echo '<label class="col-md-4 control-label">' . $label;
	if ($help !== '') {
		echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
	}
	echo '</label>';
	echo '<div class="col-md-3"><input class="configKey form-control" data-l1key="' . $key . '" placeholder="' . $default . '"/></div>';
	if ($unit !== '') {
		echo '<div class="col-md-1" style="padding-top:7px">' . $unit . '</div>';
	}
	echo '</div>';
}
?>
<form class="form-horizontal">
	<fieldset>

		<legend><i class="fas fa-shield-alt"></i> {{Sécurité et fonctionnement}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Mode simulation}}
				<sup><i class="fas fa-question-circle tooltips" title="{{Calcule et journalise toutes les décisions sans envoyer aucun ordre. Actif par défaut à l'installation.}}"></i></sup>
			</label>
			<div class="col-md-3"><input type="checkbox" class="configKey" data-l1key="simulation"/> {{Aucun ordre envoyé}}</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Cadence de décision}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="cycle">
					<option value="10">{{10 secondes (maximum de réactivité)}}</option>
					<option value="20">{{20 secondes}}</option>
					<option value="30">{{30 secondes}}</option>
					<option value="60">{{60 secondes}}</option>
				</select>
			</div>
		</div>
		<?php em_val_row('stale_age', '{{Âge maximal d\'une mesure}}', '300', '{{Au-delà, la donnée est périmée : aucun pilotage et alerte.}}', 's'); ?>
		<?php em_val_row('stale_age_soc', '{{Âge maximal du SOC batterie}}', '0', '{{0 = pas de contrôle d\'âge (le SOC varie peu). Seule l\'absence de valeur bloque le pilotage.}}', 's'); ?>
		<?php em_val_row('feedback_delay', '{{Délai de confirmation d\'un ordre}}', '90', '{{Un ordre Victron/Ahoy non confirmé par son retour d\'information après ce délai déclenche une alerte.}}', 's'); ?>

		<legend><i class="fab fa-discord"></i> {{Alertes}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Webhook Discord d'urgence}}</label>
			<div class="col-md-6"><input class="configKey form-control" data-l1key="discord_webhook" placeholder="https://discord.com/api/webhooks/..."/></div>
		</div>
		<?php em_cmd_row('cmd_ask', '{{Commande de message avec "ask" (plugin Discord)}}', 'action', '{{Utilisée pour demander s\'il faut compléter le ballon avec EDF.}}'); ?>
		<?php em_val_row('alert_repeat', '{{Répétition des alertes}}', '300', '{{Une alerte est renvoyée en boucle tant que le problème persiste.}}', 's'); ?>
		<?php em_val_row('inj_critical', '{{Injection critique}}', '500', '', 'W'); ?>
		<?php em_val_row('inj_legal', '{{Injection maximale légale}}', '3000', '', 'W'); ?>

		<legend><i class="fas fa-solar-panel"></i> {{Mesures}}</legend>
		<?php em_cmd_row('cmd_grid', '{{Puissance réseau (signée)}}', 'info', '{{Positif = soutirage EDF, négatif = injection.}}'); ?>
		<?php em_cmd_row('cmd_pv', '{{Production PV totale}}'); ?>
		<?php em_cmd_row('cmd_batt', '{{Puissance batterie (signée)}}', 'info', '{{Positif = charge, négatif = décharge.}}'); ?>
		<?php em_cmd_row('cmd_soc', '{{SOC batterie}}'); ?>
		<?php em_cmd_row('cmd_house', '{{Puissance maison}}'); ?>
		<?php em_cmd_row('cmd_kwh_grid', '{{kWh du jour soutirés EDF}}'); ?>
		<?php em_cmd_row('cmd_kwh_house', '{{kWh du jour consommés (maison)}}'); ?>
		<?php em_cmd_row('cmd_kwh_charge', '{{kWh du jour chargés (batterie)}}'); ?>
		<?php em_cmd_row('cmd_kwh_discharge', '{{kWh du jour déchargés (batterie)}}'); ?>

		<legend><i class="fas fa-car-battery"></i> {{Victron MultiPlus-II (ordres et retours)}}</legend>
		<?php em_cmd_row('cmd_charge_limit', '{{Ordre : puissance de charge batterie}}', 'action', '{{0 à 3680 W, par pas de 5 A.}}'); ?>
		<?php em_cmd_row('cmd_charge_limit_fb', '{{Retour : puissance de charge batterie}}'); ?>
		<?php em_cmd_row('cmd_inv_limit', '{{Ordre : puissance onduleur}}', 'action', '{{0 à 4000 W, par pas de 50 W.}}'); ?>
		<?php em_cmd_row('cmd_inv_limit_fb', '{{Retour : puissance onduleur}}'); ?>
		<?php em_cmd_row('cmd_soc_min', '{{Ordre : SOC mini}}', 'action', '{{5 à 100 %.}}'); ?>
		<?php em_cmd_row('cmd_soc_min_fb', '{{Retour : SOC mini}}'); ?>
		<?php em_val_row('soc_min', '{{SOC mini de base}}', '10', '{{Doit tenir jusqu\'au lever du soleil (apprentissage prévu en v0.3).}}', '%'); ?>
		<?php em_val_row('charge_max', '{{Charge batterie maximale}}', '3680', '', 'W'); ?>
		<?php em_val_row('inv_max', '{{Puissance onduleur maximale}}', '4000', '', 'W'); ?>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Cible de charge de la batterie}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="batt_target">
					<option value="100">100 %</option>
					<option value="95">95 % ({{garde de la place pour un surplus}})</option>
				</select>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Priorité de charge}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="tesla_priority">
					<option value="tesla">{{Tesla d'abord}}</option>
					<option value="battery">{{Batterie maison d'abord}}</option>
				</select>
			</div>
		</div>

		<legend><i class="fas fa-sun"></i> {{Micro-onduleurs Hoymiles (AhoyDTU)}}</legend>
		<?php em_cmd_row('cmd_hoy_limit', '{{Ordre : limite de puissance (curseur)}}', 'action', '{{Dernier recours : le but est de toujours le laisser au maximum et d\'allumer des consommateurs.}}'); ?>
		<?php em_cmd_row('cmd_hoy_limit_fb', '{{Retour : limite de puissance}}'); ?>
		<?php em_val_row('hoy_max', '{{Puissance maximale}}', '7600', '', 'W'); ?>

		<legend><i class="fas fa-thermometer-half"></i> {{Températures et présence}}</legend>
		<?php em_cmd_row('cmd_temp_ext_front', '{{Température extérieure devant (nord/nord-ouest)}}'); ?>
		<?php em_cmd_row('cmd_temp_ext_back', '{{Température extérieure derrière (sud/sud-est)}}'); ?>
		<?php em_val_row('season_heat_below', '{{Chauffage sous (moyenne 24 h)}}', '15', '', '°C'); ?>
		<?php em_val_row('season_cool_above', '{{Refroidissement au-dessus (moyenne 24 h)}}', '24', '', '°C'); ?>
		<?php em_cmd_row('cmd_presence_parents', '{{Présence des parents (1 ou 0)}}'); ?>

		<legend><i class="fas fa-euro-sign"></i> {{Tarification EDF}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Plages d'heures creuses}}
				<sup><i class="fas fa-question-circle tooltips" title="{{Ajoutez une plage par période d'heures creuses. Le reste du temps est en heures pleines.}}"></i></sup>
			</label>
			<div class="col-md-6">
				<div id="emSlots"></div>
				<a class="btn btn-default btn-sm" id="emAddSlot"><i class="fas fa-plus-circle"></i> {{Ajouter une plage}}</a>
				<input type="hidden" class="configKey" data-l1key="hc_slots"/>
			</div>
		</div>
		<?php em_val_row('price_hc', '{{Prix du kWh heures creuses}}', '', '', '€'); ?>
		<?php em_val_row('price_hp', '{{Prix du kWh heures pleines}}', '', '', '€'); ?>

		<legend><i class="fas fa-cloud-sun"></i> {{Prévisions}}</legend>
		<?php em_cmd_row('cmd_forecast_kwh', '{{Prévision PV restante aujourd\'hui (kWh), optionnel}}', 'info', '{{Solcast + MeteoFull arriveront en v0.3. En attendant, une commande suffit.}}'); ?>
		<?php em_val_row('forecast_min_kwh', '{{Seuil de prévision médiocre}}', '8', '', 'kWh'); ?>

	</fieldset>
</form>

<script>
	$('.emCmdPick').off('click').on('click', function () {
		var input = $(this).closest('.input-group').find('input');
		jeedom.cmd.getSelectModal({cmd: {type: $(this).data('type')}}, function (result) {
			input.value(result.human);
		});
	});
</script>

<script>
(function () {
	var $store = $('.configKey[data-l1key=hc_slots]');
	var $box = $('#emSlots');

	function norm(v) { // "22:00", "22h00" ou "2200" -> "22h00"
		var m = ('0000' + String(v).replace(/\D/g, '')).slice(-4);
		return m.slice(0, 2) + 'h' + m.slice(2);
	}
	function sync() {
		// Format texte "00:50-06:50;14:20-16:20" (Jeedom décode automatiquement toute valeur qui ressemble à du JSON)
		var list = [];
		$box.find('.emSlot').each(function () {
			list.push($(this).find('.emS').val().replace('h', ':') + '-' + $(this).find('.emE').val().replace('h', ':'));
		});
		$store.val(list.join(';')).trigger('change');
	}
	function picker($input) {
		$input.clockTimePicker({
			alwaysSelectHoursFirst: true, autosize: false, separator: 'h', precision: 5,
			minimum: '00h00', maximum: '23h59',
			colors: { buttonTextColor: '#0797FF', clockFaceColor: '#EEEEEE', clockInnerCircleTextColor: '#888888',
				clockInnerCircleUnselectableTextColor: '#CCCCCC', clockOuterCircleTextColor: '#000000',
				clockOuterCircleUnselectableTextColor: '#CCCCCC', hoverCircleColor: '#DDDDDD', popupBackgroundColor: '#FFFFFF',
				popupHeaderBackgroundColor: '#0797FF', popupHeaderTextColor: '#FFFFFF', selectorColor: '#0797FF', selectorNumberColor: '#FFFFFF' },
			onChange: function () { sync(); }
		});
	}
	function addRow(s, e) {
		var $r = $('<div class="emSlot" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">' +
			'<span>{{De}}</span><input type="text" class="emS form-control" style="width:90px;text-align:center;font-weight:bold">' +
			'<span>{{à}}</span><input type="text" class="emE form-control" style="width:90px;text-align:center;font-weight:bold">' +
			'<a class="btn btn-danger btn-xs emDel"><i class="fas fa-trash"></i></a></div>');
		$r.find('.emS').val(norm(s));
		$r.find('.emE').val(norm(e));
		$box.append($r);
		picker($r.find('.emS'));
		picker($r.find('.emE'));
	}
	function load(v) {
		var list = [], re = /(\d{1,2})[:h](\d{2})\s*-\s*(\d{1,2})[:h](\d{2})/g, m;
		while ((m = re.exec(String(v || ''))) !== null) { list.push({ s: m[1] + ':' + m[2], e: m[3] + ':' + m[4] }); }
		$box.empty();
		list.forEach(function (sl) { addRow(sl.s, sl.e); });
	}
	$('#emAddSlot').off('click').on('click', function () { addRow('00:00', '06:00'); sync(); });
	$box.off('click', '.emDel').on('click', '.emDel', function () { $(this).closest('.emSlot').remove(); sync(); });

	$.getScript('plugins/energyManager/3rdparty/jquery-clock-timepicker.min.js', function () {
		var tries = 0;
		var t = setInterval(function () { // attend que Jeedom ait rempli les champs de configuration
			tries++;
			var v = $store.val();
			if (v || tries > 15) { clearInterval(t); load(v || ''); }
		}, 200);
	});
})();
</script>
