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

	/* Simulateur : configuration réelle du plugin (équipements, paramètres), valeurs de départ neutres. */
	if (init('action') == 'sim_init') {
		$equips = array();
		foreach (energyManager::buildEquips(time(), 0, null) as $e) {
			$e['is_on'] = false;
			$e['power_real'] = 0;
			if ($e['type'] === 'ecs') {
				$eq = eqLogic::byId($e['id']);
				$e['zero_delay'] = is_object($eq) ? (int) $eq->getConfiguration('zero_delay', 120) : 120;
				$e['water_temp'] = 40;
				$e['done_today'] = false;
				$e['force_edf'] = false;
				$e['forecast_poor'] = false;
			} elseif ($e['type'] === 'simple') {
				$e['temp'] = 18;
				$e['window_open'] = false;
				$e['season_heat'] = true;
			} elseif ($e['type'] === 'tesla') {
				$e['mode'] = 'auto';
				$e['active'] = false;
				$e['tesla_mode'] = 'Stand-By';
				$e['plugged'] = false;
				$e['plug_handled'] = false;
				$e['margin'] = 0;
			}
			$equips[] = $e;
		}
		$demo = (count($equips) == 0);
		if ($demo) {
			// aucun équipement créé : jeu d'essai pour pouvoir découvrir le comportement
			$base = array('mode' => 'auto', 'piloted' => true, 'is_on' => false, 'power_real' => 0);
			$equips = array(
				array_merge($base, array('id' => 9001, 'type' => 'ecs', 'name' => 'Ballon (démo)', 'power_theo' => 3000, 'priority' => 1,
					'water_temp' => 40, 'min_temp' => 50, 'done_today' => false, 'force_edf' => false, 'zero_threshold' => 20,
					'cut_on_zero' => 1, 'forecast_poor' => false, 'zero_delay' => 120)),
				array_merge($base, array('id' => 9002, 'type' => 'simple', 'name' => 'Radiateur bureau (démo)', 'power_theo' => 1000, 'priority' => 1,
					'temp' => 18, 'target' => 20, 'boost' => 2, 'window_open' => false, 'season_heat' => true)),
				array_merge($base, array('id' => 9003, 'type' => 'simple', 'name' => 'Radiateur étage (démo)', 'power_theo' => 1500, 'priority' => 2,
					'temp' => 18, 'target' => 20, 'boost' => 2, 'window_open' => false, 'season_heat' => true)),
				array_merge($base, array('id' => 9004, 'type' => 'tesla', 'name' => 'Tesla (démo)', 'power_theo' => 7200, 'priority' => 3,
					'active' => false, 'tesla_mode' => 'Stand-By', 'plugged' => false, 'plug_handled' => false, 'margin' => 0, 'aggr_w' => 1000)),
			);
		}
		ajax::success(array(
			'equips' => $equips, 'demo' => $demo, 'cfg' => array_merge(energyManagerEngine::DEFAULTS, energyManager::engineConfig()),
			'hc_slots' => (string) energyManager::cfg('hc_slots', ''),
			'tesla_priority' => energyManager::cfg('tesla_priority', 'tesla'), 'batt_target' => (int) energyManager::cfg('batt_target', 100),
		));
	}

	/* Simulateur : un cycle du moteur sur l'état simulé envoyé par la page. Rien n'est envoyé à l'installation. */
	if (init('action') == 'sim_step') {
		$in = json_decode(init('state'), true);
		if (!is_array($in)) {
			throw new Exception(__('Entrées invalides', __FILE__));
		}
		$minute = max(0, min(1439, (int) $in['minute']));
		$hc = energyManager::hcInfo($minute);
		$cfg = energyManager::engineConfig();
		if (isset($in['cfg']['batt_target'])) {
			$cfg['batt_target'] = ((int) $in['cfg']['batt_target'] === 95) ? 95 : 100;
		}
		if (isset($in['cfg']['night_reserve'])) {
			$cfg['night_reserve'] = max(0, min(100, (float) $in['cfg']['night_reserve']));
		}
		if (isset($in['cfg']['tesla_priority'])) {
			$cfg['tesla_priority'] = ($in['cfg']['tesla_priority'] === 'battery') ? 'battery' : 'tesla';
		}
		$equips = array();
		$names = array();
		foreach ((isset($in['equips']) && is_array($in['equips'])) ? $in['equips'] : array() as $e) {
			if (!is_array($e) || !isset($e['id'], $e['type'])) {
				continue;
			}
			$names[$e['id']] = isset($e['name']) ? $e['name'] : '#' . $e['id'];
			if (!empty($e['piloted'])) {
				$equips[] = $e;
			}
		}
		$state = array(
			'now' => (int) $in['now'], 'minute' => $minute,
			'grid' => (float) $in['grid'], 'pv' => (float) $in['pv'], 'soc' => (float) $in['soc'],
			'batt' => (float) $in['batt'], 'house' => (float) $in['house'],
			'forecast_kwh' => (isset($in['forecast_kwh']) && is_numeric($in['forecast_kwh'])) ? (float) $in['forecast_kwh'] : null,
			'stale' => !empty($in['stale']) ? array('cmd_grid (panne simulée)') : array(),
			'hc' => ($hc['slot'] === 'HC'), 'hc_afternoon' => $hc['afternoon'],
			'cfg' => $cfg, 'hoy_limit' => (float) $in['hoy_limit'], 'charge_limit' => (float) $in['charge_limit'],
			'equips' => $equips, 'mem' => (isset($in['mem']) && is_array($in['mem'])) ? $in['mem'] : array(),
		);
		ajax::success(array('result' => energyManagerEngine::decide($state), 'names' => $names, 'slot' => $hc['slot'], 'hc_afternoon' => $hc['afternoon']));
	}

	throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
	ajax::error(displayException($e), $e->getCode());
}
