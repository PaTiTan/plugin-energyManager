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

try {
	require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
	include_file('core', 'authentification', 'php');

	if (!isConnect('admin')) {
		throw new Exception(__('401 - Accès non autorisé', __FILE__));
	}

	ajax::init();

	/* Simulateur : exécute le moteur sur des valeurs saisies, sans rien envoyer. */
	if (init('action') == 'simulate') {
		$in = json_decode(init('inputs'), true);
		if (!is_array($in)) {
			throw new Exception(__('Entrées invalides', __FILE__));
		}
		$state = array(
			'now' => time(), 'minute' => (int) $in['hour'] * 60 + (int) $in['min'],
			'grid' => (float) $in['grid'], 'pv' => (float) $in['pv'], 'soc' => (float) $in['soc'],
			'batt' => 0, 'house' => 0, 'stale' => array(), 'hc' => false,
			'hc_afternoon' => !empty($in['hc_afternoon']),
			'cfg' => energyManager::engineConfig(),
			'hoy_limit' => (float) $in['hoy_limit'], 'charge_limit' => (float) $in['charge_limit'],
			'equips' => array_values(array_filter(energyManager::buildEquips(time(), 0, null), function ($e) { return $e['piloted']; })), 'mem' => array(),
		);
		// valeurs saisies pour les pièces et le ballon
		foreach ($state['equips'] as &$e) {
			if ($e['type'] === 'simple' && isset($in['room_temp'])) {
				$e['temp'] = (float) $in['room_temp'];
				$e['season_heat'] = !empty($in['season_heat']);
			}
			if ($e['type'] === 'ecs' && isset($in['water_temp'])) {
				$e['water_temp'] = (float) $in['water_temp'];
			}
		}
		unset($e);
		$res = energyManagerEngine::decide($state);
		$names = array();
		foreach ($state['equips'] as $e) {
			$names[$e['id']] = $e['name'];
		}
		ajax::success(array('result' => $res, 'names' => $names));
	}

	throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
	ajax::error(displayException($e), $e->getCode());
}
