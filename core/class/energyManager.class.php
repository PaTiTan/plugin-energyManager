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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/energyManagerEngine.class.php';

class energyManager extends eqLogic {

	/* *************************** Constantes *************************** */

	const TYPES = array('manager', 'simple', 'ecs', 'tesla', 'clim');

	/* ************************** Configuration ************************* */

	/** Lecture d'un paramètre du plugin avec valeur par défaut (même si la clé est vide). */
	public static function cfg($_key, $_default = '') {
		$v = config::byKey($_key, 'energyManager', '');
		return ($v === '' || $v === null) ? $_default : $v;
	}

	/** Résout une commande depuis sa forme humaine (#[Objet][Equipement][Commande]#). */
	public static function resolveCmd($_human) {
		if (!is_string($_human) || trim($_human) === '') {
			return null;
		}
		$cmd = cmd::byString($_human);
		return is_object($cmd) ? $cmd : null;
	}

	/**
	 * Lit la valeur d'une commande info.
	 * @return array|null array(value, age en secondes) ou null si la commande est absente
	 */
	public static function readCmd($_human) {
		$cmd = self::resolveCmd($_human);
		if ($cmd === null || $cmd->getType() != 'info') {
			return null;
		}
		$value = $cmd->execCmd();
		$date = $cmd->getCollectDate();
		$age = ($date === '' || $date === null) ? 0 : max(0, time() - strtotime($date));
		return array('value' => $value, 'age' => $age);
	}

	/** Mesure numérique du plugin (clé de configuration) ; null si absente ou non numérique. */
	private static function measure($_key, &$_stale, $_staleAge) {
		$r = self::readCmd(self::cfg($_key));
		if ($r === null || !is_numeric($r['value'])) {
			$_stale[] = $_key;
			return null;
		}
		if ($_staleAge > 0 && $r['age'] > $_staleAge) {
			$_stale[] = $_key . ' (' . $r['age'] . ' s)';
		}
		return (float) $r['value'];
	}

	/* ****************************** Démon ***************************** */

	public static function deamon_info() {
		$return = array('log' => 'energyManager', 'state' => 'nok', 'launchable' => 'ok', 'launchable_message' => '');
		$cron = cron::byClassAndFunction(__CLASS__, 'daemon');
		if (is_object($cron) && $cron->running()) {
			$return['state'] = 'ok';
		}
		return $return;
	}

	public static function deamon_start() {
		self::deamon_stop();
		$info = self::deamon_info();
		if ($info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		$cron = cron::byClassAndFunction(__CLASS__, 'daemon');
		if (!is_object($cron)) {
			$cron = new cron();
			$cron->setClass(__CLASS__);
			$cron->setFunction('daemon');
			$cron->setEnable(1);
			$cron->setDeamon(1);
			$cron->setTimeout(1440);
			$cron->setSchedule('* * * * *');
		}
		$cycle = max(5, min(60, (int) self::cfg('cycle', 10)));
		$cron->setDeamonSleepTime($cycle);
		$cron->save();
		$cron->run();
	}

	public static function deamon_stop() {
		$cron = cron::byClassAndFunction(__CLASS__, 'daemon');
		if (is_object($cron)) {
			$cron->halt();
		}
	}

	/** Lit une valeur JSON du cache Jeedom (texte JSON ou valeur déjà décodée). */
	private static function cacheJson($_key) {
		$v = cache::byKey($_key)->getValue('');
		if (is_array($v)) {
			return $v;
		}
		$d = json_decode((string) $v, true);
		return is_array($d) ? $d : array();
	}

	/** Un tour de boucle du démon (Jeedom le rappelle toutes les "cycle" secondes). */
	public static function daemon() {
		try {
			self::runCycle(false);
		} catch (Exception $e) {
			log::add('energyManager', 'error', 'Cycle en erreur : ' . $e->getMessage());
		}
	}

	/* ************************** Cycle de décision ********************* */

	/**
	 * Un cycle complet : lire, décider, appliquer (sauf simulation), alerter.
	 * @param bool $_dry true : ne rien envoyer (utilisé par le simulateur côté serveur)
	 */
	public static function runCycle($_dry = false) {
		$now = time();
		$staleAge = (int) self::cfg('stale_age', 300);
		$stale = array();
		$ignored = array();
		$simulation = $_dry || (int) self::cfg('simulation', 1) === 1;

		$minute = (int) date('G', $now) * 60 + (int) date('i', $now);
		$slots = energyManagerEngine::parseSlots(self::cfg('hc_slots', ''));
		$slot = energyManagerEngine::slotAt($slots, $minute);
		$hcAfternoon = false;
		$hcAfternoonEnd = null;
		foreach ($slots as $sl) {
			if ($sl[2] === 'HC' && $sl[0] >= 720 && $sl[0] < 1080) {
				$hcAfternoonEnd = $sl[1];
				if ($minute >= $sl[0] && $minute < $sl[1]) {
					$hcAfternoon = true;
				}
			}
		}

		$state = array(
			'now' => $now, 'minute' => $minute,
			'grid' => self::measure('cmd_grid', $stale, $staleAge),
			'pv' => self::measure('cmd_pv', $stale, $staleAge),
			'soc' => self::measure('cmd_soc', $stale, $staleAge),
			// batterie et maison sont informatives : leur absence ne bloque pas le pilotage
			'batt' => self::measure('cmd_batt', $ignored, $staleAge),
			'house' => self::measure('cmd_house', $ignored, $staleAge),
			'stale' => $stale,
			'hc' => ($slot === 'HC'), 'hc_afternoon' => $hcAfternoon,
			'cfg' => self::engineConfig(),
			'equips' => self::buildEquips($now, $minute, $hcAfternoonEnd),
			'mem' => self::cacheJson('energyManager::mem'),
		);
		$hoy = self::readCmd(self::cfg('cmd_hoy_limit_fb'));
		$chg = self::readCmd(self::cfg('cmd_charge_limit_fb'));
		$state['hoy_limit'] = ($hoy !== null && is_numeric($hoy['value'])) ? (float) $hoy['value'] : (float) $state['cfg']['hoy_max'];
		$state['charge_limit'] = ($chg !== null && is_numeric($chg['value'])) ? (float) $chg['value'] : (float) $state['cfg']['charge_max'];

		$result = energyManagerEngine::decide($state);
		cache::set('energyManager::mem', json_encode($result['mem']));

		// post-traitement ballon : thermostat atteint = consommation retombée à zéro
		self::ecsThermostatWatch($state['equips'], $now, $simulation, $result);

		self::mirror($state, $result, $slot, $simulation);

		foreach ($result['actions'] as $a) {
			log::add('energyManager', $simulation ? 'info' : 'debug', ($simulation ? '[SIMULATION] ' : '') . self::describeAction($a));
			if (!$simulation) {
				self::applyAction($a, $now);
			}
		}
		if (!$simulation) {
			self::syncTeslaPriority();
		}
		self::checkFeedback($now);
		self::ecsAsk($state['equips'], $minute, $hcAfternoonEnd);
		self::handleAlerts($result['alerts'], $now);
		return $result;
	}

	/** Paramètres du moteur : valeurs du plugin + sélecteurs du widget. */
	public static function engineConfig() {
		return array(
			'soc_min' => (float) self::cfg('soc_min', 10),
			'batt_target' => (int) self::cfg('batt_target', 100),
			'charge_max' => (float) self::cfg('charge_max', 3680),
			'inv_max' => (float) self::cfg('inv_max', 4000),
			'hoy_max' => (float) self::cfg('hoy_max', 7600),
			'inj_critical' => (float) self::cfg('inj_critical', 500),
			'inj_legal' => (float) self::cfg('inj_legal', 3000),
			'imp_off' => (float) self::cfg('imp_off', 150),
			'min_pv' => (float) self::cfg('min_pv', 300),
			'tesla_priority' => self::cfg('tesla_priority', 'tesla'),
		);
	}

	/** Construit la liste des équipements gérés pour le moteur. */
	public static function buildEquips($_now, $_minute, $_hcEnd) {
		$list = array();
		$season = self::season();
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			$type = $eq->getConfiguration('eqType', 'simple');
			if ($type === 'manager') {
				continue;
			}
			$pr = self::readCmd($eq->getConfiguration('cmd_power'));
			$st = self::readCmd($eq->getConfiguration('cmd_state'));
			$power = ($pr !== null && is_numeric($pr['value'])) ? (float) $pr['value'] : 0;
			$zero = (float) $eq->getConfiguration('zero_threshold', 20);
			if ($st !== null && $st['value'] !== '') {
				$isOn = ((int) $st['value'] === 1);
			} else {
				$isOn = ($power > $zero);
			}
			$e = array(
				'id' => $eq->getId(), 'type' => $type, 'name' => $eq->getName(),
				'mode' => $eq->getConfiguration('mode', 'auto'),
				'is_on' => $isOn, 'power_real' => $power,
				'power_theo' => (float) $eq->getConfiguration('power_theo', 1000),
				'priority' => (int) $eq->getConfiguration('priority', 2),
			);
			if ($type === 'simple') {
				$t = self::readCmd($eq->getConfiguration('cmd_temp'));
				$w = self::readCmd($eq->getConfiguration('cmd_window'));
				$e['temp'] = ($t !== null && is_numeric($t['value'])) ? (float) $t['value'] : null;
				$e['target'] = (float) $eq->getConfiguration('target', 20);
				$e['boost'] = (float) $eq->getConfiguration('boost', 2);
				$e['window_open'] = ($w !== null && (int) $w['value'] === 1);
				$e['season_heat'] = ($season === 'heat');
			} elseif ($type === 'ecs') {
				$t = self::readCmd($eq->getConfiguration('cmd_water_temp'));
				$e['water_temp'] = ($t !== null && is_numeric($t['value'])) ? (float) $t['value'] : null;
				$e['min_temp'] = (float) $eq->getConfiguration('min_temp', 52);
				$e['done_today'] = (cache::byKey('energyManager::ecs_done::' . $eq->getId())->getValue('') === date('Ymd'));
				$e['force_edf'] = (cache::byKey('energyManager::ecs_force::' . $eq->getId())->getValue('') === date('Ymd'));
				$e['zero_threshold'] = $zero;
				$e['cut_on_zero'] = (int) $eq->getConfiguration('cut_on_zero', 1);
				$e['forecast_poor'] = self::forecastPoor();
			} elseif ($type === 'tesla') {
				// le mode est décidé et mémorisé par teslasmartcharge (il bascule seul en Stand-By si débranchée ou cible atteinte)
				$m = self::readCmd($eq->getConfiguration('cmd_mode_info'));
				$tm = ($m !== null) ? (string) $m['value'] : '';
				$e['mode'] = ($tm === 'Non actif') ? 'off' : 'auto';
				$e['active'] = ($tm === 'Solaire');
				$e['tesla_mode'] = $tm;
			}
			$list[] = $e;
		}
		return $list;
	}

	/** Saison de chauffe/refroidissement selon les 2 sondes extérieures (moyenne 24 h). */
	public static function season() {
		$c = self::cacheJson('energyManager::season');
		if (is_array($c) && isset($c['t']) && (time() - $c['t']) < 600) {
			return $c['v'];
		}
		$vals = array();
		foreach (array('cmd_temp_ext_front', 'cmd_temp_ext_back') as $k) {
			$cmd = self::resolveCmd(self::cfg($k));
			if ($cmd === null) {
				continue;
			}
			$avg = null;
			try {
				$stat = history::getStatistique($cmd->getId(), date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s'));
				if (isset($stat['avg']) && is_numeric($stat['avg']) && $stat['avg'] !== '') {
					$avg = (float) $stat['avg'];
				}
			} catch (Exception $e) {
			}
			if ($avg === null && is_numeric($cmd->execCmd())) {
				$avg = (float) $cmd->execCmd();
			}
			if ($avg !== null) {
				$vals[] = $avg;
			}
		}
		$v = 'off';
		if (count($vals) > 0) {
			$m = array_sum($vals) / count($vals);
			if ($m < (float) self::cfg('season_heat_below', 15)) {
				$v = 'heat';
			} elseif ($m > (float) self::cfg('season_cool_above', 24)) {
				$v = 'cool';
			}
		}
		cache::set('energyManager::season', json_encode(array('t' => time(), 'v' => $v)));
		return $v;
	}

	/** Prévision solaire médiocre ? (commande optionnelle en kWh restants aujourd'hui) */
	public static function forecastPoor() {
		$r = self::readCmd(self::cfg('cmd_forecast_kwh'));
		if ($r === null || !is_numeric($r['value'])) {
			return false;
		}
		return (float) $r['value'] < (float) self::cfg('forecast_min_kwh', 8);
	}

	/* ***************************** Ballon ECS ********************************** */

	/**
	 * Thermostat interne atteint : l'équipement était allumé et sa consommation est retombée à zéro.
	 * Le ballon est alors "terminé" pour la journée (et coupé si l'option est active).
	 */
	private static function ecsThermostatWatch(array $_equips, $_now, $_simulation, array &$_result) {
		foreach ($_equips as $e) {
			if ($e['type'] !== 'ecs') {
				continue;
			}
			$key = 'energyManager::ecs_on_since::' . $e['id'];
			$eq = eqLogic::byId($e['id']);
			if (!is_object($eq)) {
				continue;
			}
			if ($e['is_on'] && $e['power_real'] > $e['zero_threshold']) {
				if (cache::byKey($key)->getValue(0) == 0) {
					cache::set($key, $_now);
				}
				continue;
			}
			$since = (int) cache::byKey($key)->getValue(0);
			if ($e['is_on'] && $since > 0 && ($_now - $since) >= (int) $eq->getConfiguration('zero_delay', 120)) {
				// la résistance a tourné puis ne consomme plus : le thermostat a coupé
				cache::set('energyManager::ecs_done::' . $e['id'], date('Ymd'));
				cache::set($key, 0);
				if ($eq->getConfiguration('cut_on_zero', 1)) {
					$_result['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'off',
						'reason' => 'ballon : thermostat atteint (consommation à zéro), passage inactif pour la journée');
				}
			} elseif (!$e['is_on']) {
				cache::set($key, 0);
			}
		}
	}

	/** Demande Discord (ask) quand l'heure creuse d'après-midi est finie et que le ballon est resté froid. */
	private static function ecsAsk(array $_equips, $_minute, $_hcEnd) {
		if ($_hcEnd === null || $_minute < $_hcEnd || $_minute > $_hcEnd + 180) {
			return;
		}
		foreach ($_equips as $e) {
			if ($e['type'] !== 'ecs' || $e['mode'] !== 'auto' || $e['done_today'] || $e['force_edf']) {
				continue;
			}
			if ($e['water_temp'] === null || $e['water_temp'] >= $e['min_temp']) {
				continue;
			}
			$k = 'energyManager::ecs_asked::' . $e['id'];
			if (cache::byKey($k)->getValue('') === date('Ymd')) {
				continue;
			}
			cache::set($k, date('Ymd'));
			$msg = 'Le ballon « ' . $e['name'] . ' » est à ' . $e['water_temp'] . ' °C (minimum ' . $e['min_temp'] . ' °C) et l\'heure creuse est passée. Compléter avec EDF (hors heures creuses) ?';
			$ask = self::resolveCmd(self::cfg('cmd_ask'));
			$main = self::mainEq();
			$answerCmd = is_object($main) ? $main->getCmd(null, 'ecs_answer') : null;
			if ($ask !== null && is_object($answerCmd)) {
				try {
					$ask->execCmd(array(
						'title' => 'Energy Manager',
						'message' => $msg,
						'answer' => array('Oui', 'Non'),
						'timeout' => 7200,
						'cmd' => $answerCmd->getId(),
					));
				} catch (Exception $ex) {
					log::add('energyManager', 'error', 'Ask Discord impossible : ' . $ex->getMessage());
				}
			}
			self::notify('warning', $msg . ' (bouton « Compléter avec EDF » sur l\'équipement).');
		}
	}

	/* ************************** Application des ordres ********************* */

	private static function describeAction(array $a) {
		switch ($a['type']) {
			case 'equip':
				$eq = eqLogic::byId($a['id']);
				return (is_object($eq) ? $eq->getName() : '#' . $a['id']) . ' → ' . strtoupper($a['cmd']) . ' : ' . $a['reason'];
			case 'hoymiles':
				return 'Limite Hoymiles → ' . $a['value'] . ' W : ' . $a['reason'];
			case 'charge_limit':
				return 'Limite de charge batterie → ' . $a['value'] . ' W : ' . $a['reason'];
		}
		return json_encode($a);
	}

	private static function applyAction(array $a, $_now) {
		switch ($a['type']) {
			case 'equip':
				$eq = eqLogic::byId($a['id']);
				if (!is_object($eq)) {
					return;
				}
				$cmd = self::resolveCmd($eq->getConfiguration($a['cmd'] === 'on' ? 'cmd_on' : 'cmd_off'));
				if ($cmd === null) {
					self::notify('critical', 'Commande ' . $a['cmd'] . ' introuvable pour « ' . $eq->getName() . ' ».');
					return;
				}
				$cmd->execCmd();
				cache::set('energyManager::last_order::' . $a['id'], json_encode(array('cmd' => $a['cmd'], 't' => $_now)));
				$eq->checkAndUpdateCmd('last_reason', $a['reason']);
				break;
			case 'hoymiles':
				self::sendSlider('cmd_hoy_limit', $a['value'], 'hoy', $_now);
				break;
			case 'charge_limit':
				self::sendSlider('cmd_charge_limit', $a['value'], 'charge', $_now);
				break;
		}
	}

	private static function sendSlider($_cfgKey, $_value, $_fbName, $_now) {
		$cmd = self::resolveCmd(self::cfg($_cfgKey));
		if ($cmd === null) {
			self::notify('critical', 'Commande de pilotage « ' . $_cfgKey . ' » non configurée.');
			return;
		}
		$cmd->execCmd(array('slider' => $_value));
		cache::set('energyManager::pending::' . $_fbName, json_encode(array('value' => $_value, 't' => $_now)));
	}

	/**
	 * Envoie à teslasmartcharge la priorité choisie dans energyManager (voiture ou maison).
	 * Ne renvoie l'ordre que si la valeur a changé (ou si la Tesla vient d'être ajoutée).
	 */
	public static function syncTeslaPriority() {
		$wanted = (self::cfg('tesla_priority', 'tesla') === 'tesla') ? 'voiture' : 'maison';
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			if ($eq->getConfiguration('eqType') !== 'tesla') {
				continue;
			}
			$key = 'energyManager::tesla_prio::' . $eq->getId();
			if (cache::byKey($key)->getValue('') === $wanted) {
				continue;
			}
			$cmd = self::resolveCmd($eq->getConfiguration('cmd_set_priority'));
			if ($cmd === null) {
				continue;
			}
			$cmd->execCmd(array('select' => $wanted));
			cache::set($key, $wanted);
			log::add('energyManager', 'info', 'Priorité de charge envoyée à teslasmartcharge : ' . $wanted);
		}
	}

	/** Les ordres Victron / Ahoy doivent être confirmés par leur retour d'information. */
	private static function checkFeedback($_now) {
		$map = array('hoy' => 'cmd_hoy_limit_fb', 'charge' => 'cmd_charge_limit_fb');
		foreach ($map as $name => $fbKey) {
			$p = self::cacheJson('energyManager::pending::' . $name);
			if (!is_array($p) || !isset($p['t']) || ($_now - $p['t']) < (int) self::cfg('feedback_delay', 90)) {
				continue;
			}
			$fb = self::readCmd(self::cfg($fbKey));
			if ($fb === null) {
				continue; // pas de retour configuré : rien à vérifier
			}
			$tol = ($name === 'hoy') ? 100 : 60;
			if (!is_numeric($fb['value']) || abs((float) $fb['value'] - (float) $p['value']) > $tol) {
				self::sendAlert('feedback_' . $name, 'critical',
					'Pas de retour de valeur : ordre ' . $p['value'] . ' W (' . $name . ') non confirmé (retour : ' . $fb['value'] . ').', $_now);
			} else {
				cache::set('energyManager::pending::' . $name, '{}');
				cache::set('energyManager::alert::feedback_' . $name, 0);
			}
		}
	}

	/* ******************************* Alertes ********************************** */

	private static function handleAlerts(array $_alerts, $_now) {
		$active = array();
		foreach ($_alerts as $al) {
			$active[$al['key']] = true;
			self::sendAlert($al['key'], $al['level'], $al['message'], $_now);
		}
		foreach (array('stale', 'incoherent', 'injection', 'injection_legal') as $k) {
			if (!isset($active[$k])) {
				cache::set('energyManager::alert::' . $k, 0); // résolue : on réarme
			}
		}
	}

	/** Envoie une alerte en boucle (toutes les "alert_repeat" secondes) tant qu'elle persiste. */
	private static function sendAlert($_key, $_level, $_message, $_now) {
		$last = (int) cache::byKey('energyManager::alert::' . $_key)->getValue(0);
		if (($_now - $last) < (int) self::cfg('alert_repeat', 300)) {
			return;
		}
		cache::set('energyManager::alert::' . $_key, $_now);
		self::notify($_level, $_message);
	}

	/** Message Jeedom + webhook Discord d'urgence. */
	public static function notify($_level, $_message) {
		log::add('energyManager', $_level === 'critical' ? 'error' : 'warning', $_message);
		message::add('energyManager', $_message);
		$hook = trim((string) self::cfg('discord_webhook', ''));
		if ($hook === '' || strpos($hook, 'https://discord.com/api/webhooks/') !== 0) {
			return;
		}
		$payload = json_encode(array('content' => ($_level === 'critical' ? '🚨 ' : '⚠️ ') . '**Energy Manager** : ' . $_message));
		$ch = curl_init($hook);
		curl_setopt_array($ch, array(
			CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json'), CURLOPT_TIMEOUT => 8,
		));
		curl_exec($ch);
		curl_close($ch);
	}

	/* ****************** Recopie dans les commandes Jeedom ********************* */

	private static function mirror(array $_state, array $_result, $_slot, $_simulation) {
		$main = self::mainEq();
		if (!is_object($main)) {
			return;
		}
		$map = array('pv' => 'pv', 'grid' => 'grid', 'batt' => 'batt', 'soc' => 'soc', 'house' => 'house');
		foreach ($map as $k => $logical) {
			if (isset($_state[$k]) && $_state[$k] !== null) {
				$main->checkAndUpdateCmd($logical, $_state[$k]);
			}
		}
		$main->checkAndUpdateCmd('hoy_limit', $_state['hoy_limit']);
		$main->checkAndUpdateCmd('charge_limit', $_state['charge_limit']);
		$main->checkAndUpdateCmd('slot', $_slot);
		$main->checkAndUpdateCmd('season', self::season());
		$main->checkAndUpdateCmd('sim_info', $_simulation ? 1 : 0);
		$main->checkAndUpdateCmd('tesla_priority_info', self::cfg('tesla_priority', 'tesla'));
		$main->checkAndUpdateCmd('batt_target_info', self::cfg('batt_target', 100));
		if (isset($_result['info']['decision'])) {
			$main->checkAndUpdateCmd('decision', ($_simulation ? '[SIMULATION] ' : '') . $_result['info']['decision']);
		}
		if (isset($_result['info']['injection'])) {
			$main->checkAndUpdateCmd('injection', $_result['info']['injection']);
		}
		foreach ($_state['equips'] as $e) {
			$eq = eqLogic::byId($e['id']);
			if (!is_object($eq)) {
				continue;
			}
			$eq->checkAndUpdateCmd('power', $e['power_real']);
			$eq->checkAndUpdateCmd('status', $e['is_on'] ? 'ON' : 'OFF');
			$eq->checkAndUpdateCmd('mode_info', $e['type'] === 'tesla' ? (string) $e['tesla_mode'] : $eq->getConfiguration('mode', 'auto'));
		}
	}

	public static function mainEq() {
		foreach (eqLogic::byType('energyManager') as $eq) {
			if ($eq->getConfiguration('eqType') === 'manager') {
				return $eq;
			}
		}
		return null;
	}

	/** Crée l'équipement principal (widget powerFlow) s'il n'existe pas. */
	public static function ensureMainEquipment() {
		if (self::mainEq() !== null) {
			return;
		}
		$eq = new energyManager();
		$eq->setName('Gestionnaire d\'énergie');
		$eq->setEqType_name('energyManager');
		$eq->setIsEnable(1);
		$eq->setIsVisible(1);
		$eq->setConfiguration('eqType', 'manager');
		$eq->save();
	}

	/* ************************ Création des commandes ************************** */

	public function preSave() {
		if ($this->getConfiguration('eqType', '') === '') {
			$this->setConfiguration('eqType', 'simple');
		}
	}

	public function postSave() {
		$type = $this->getConfiguration('eqType', 'simple');
		if ($type === 'manager') {
			foreach (array(
				array('pv', 'Production PV', 'W'), array('grid', 'Réseau', 'W'), array('batt', 'Batterie', 'W'),
				array('soc', 'SOC batterie', '%'), array('house', 'Maison', 'W'), array('injection', 'Injection', 'W'),
				array('hoy_limit', 'Limite Hoymiles', 'W'), array('charge_limit', 'Limite charge batterie', 'W'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'info', 'numeric', $c[2]);
			}
			foreach (array(
				array('decision', 'Décision'), array('slot', 'Créneau tarifaire'), array('season', 'Saison'),
				array('tesla_priority_info', 'Priorité Tesla/Batterie'), array('batt_target_info', 'Cible batterie'),
				array('sim_info', 'Simulation'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'info', 'string');
			}
			foreach (array(
				array('prio_tesla', 'Priorité Tesla'), array('prio_battery', 'Priorité batterie'),
				array('target_95', 'Cible batterie 95 %'), array('target_100', 'Cible batterie 100 %'),
				array('sim_on', 'Simulation ON'), array('sim_off', 'Simulation OFF'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'action', 'other');
			}
			$this->createCmd('ecs_answer', 'Réponse ballon (ask)', 'action', 'message');
			return;
		}
		$this->createCmd('power', 'Puissance', 'info', 'numeric', 'W');
		$this->createCmd('status', 'Statut', 'info', 'string');
		$this->createCmd('mode_info', 'Mode', 'info', 'string');
		$this->createCmd('last_reason', 'Dernière décision', 'info', 'string');
		if ($type === 'tesla') {
			$this->createCmd('mode_solar', 'Mode solaire', 'action', 'other');
			$this->createCmd('mode_standby', 'Stand-by', 'action', 'other');
			$this->createCmd('mode_inactive', 'Non actif', 'action', 'other');
		} else {
			$this->createCmd('mode_auto', 'Mode auto', 'action', 'other');
			$this->createCmd('mode_on', 'Forcé ON', 'action', 'other');
			$this->createCmd('mode_off', 'Inactif', 'action', 'other');
		}
		if ($type === 'ecs') {
			$this->createCmd('force_edf', 'Compléter avec EDF', 'action', 'other');
		}
	}

	private function createCmd($_logicalId, $_name, $_type, $_subType, $_unit = '') {
		$cmd = $this->getCmd(null, $_logicalId);
		if (!is_object($cmd)) {
			$cmd = new energyManagerCmd();
			$cmd->setLogicalId($_logicalId);
			$cmd->setEqLogic_id($this->getId());
			$cmd->setName(__($_name, __FILE__));
			$cmd->setType($_type);
			$cmd->setSubType($_subType);
			if ($_unit !== '') {
				$cmd->setUnite($_unit);
			}
			$cmd->save();
		}
		return $cmd;
	}

	/* ******************************** Widget ********************************** */

	public function toHtml($_version = 'dashboard') {
		if ($this->getConfiguration('eqType') !== 'manager') {
			return parent::toHtml($_version);
		}
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) {
			return $replace;
		}
		$version = jeedom::versionAlias($_version);
		foreach (array('pv', 'grid', 'batt', 'soc', 'house', 'injection', 'hoy_limit', 'charge_limit', 'decision', 'sim_info',
			'tesla_priority_info', 'batt_target_info', 'slot', 'season', 'prio_tesla', 'prio_battery', 'target_95', 'target_100',
			'sim_on', 'sim_off') as $l) {
			$c = $this->getCmd(null, $l);
			$replace['#' . $l . '_id#'] = is_object($c) ? $c->getId() : 0;
			$replace['#' . $l . '_val#'] = is_object($c) ? addslashes((string) $c->execCmd()) : '';
		}
		$consumers = array();
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			if ($eq->getConfiguration('eqType') === 'manager') {
				continue;
			}
			$p = $eq->getCmd(null, 'power');
			$s = $eq->getCmd(null, 'status');
			$consumers[] = array(
				'eq' => $eq->getId(), 'name' => $eq->getName(), 'type' => $eq->getConfiguration('eqType'),
				'power_id' => is_object($p) ? $p->getId() : 0, 'power' => is_object($p) ? (float) $p->execCmd() : 0,
				'status_id' => is_object($s) ? $s->getId() : 0, 'status' => is_object($s) ? (string) $s->execCmd() : '',
			);
		}
		$replace['#consumers#'] = json_encode($consumers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$html = template_replace($replace, getTemplate('core', $version, 'energyManager', 'energyManager'));
		return $this->postToHtml($_version, $html);
	}
}

class energyManagerCmd extends cmd {

	/** Pas de rendu de commande dans l'équipement principal : le widget est global. */
	public function dontRemoveCmd() {
		return ($this->getType() == 'info');
	}

	public function execute($_options = array()) {
		$eq = $this->getEqLogic();
		$id = $this->getLogicalId();
		$type = $eq->getConfiguration('eqType');

		if ($type === 'manager') {
			switch ($id) {
				case 'prio_tesla':
					config::save('tesla_priority', 'tesla', 'energyManager');
					if ((int) energyManager::cfg('simulation', 1) !== 1) { energyManager::syncTeslaPriority(); }
					break;
				case 'prio_battery':
					config::save('tesla_priority', 'battery', 'energyManager');
					if ((int) energyManager::cfg('simulation', 1) !== 1) { energyManager::syncTeslaPriority(); }
					break;
				case 'target_95':    config::save('batt_target', 95, 'energyManager'); break;
				case 'target_100':   config::save('batt_target', 100, 'energyManager'); break;
				case 'sim_on':       config::save('simulation', 1, 'energyManager'); break;
				case 'sim_off':      config::save('simulation', 0, 'energyManager'); break;
				case 'ecs_answer':
					$answer = isset($_options['answer']) ? $_options['answer'] : (isset($_options['message']) ? $_options['message'] : '');
					if (stripos((string) $answer, 'oui') === 0) {
						foreach (eqLogic::byType('energyManager', true) as $e) {
							if ($e->getConfiguration('eqType') === 'ecs') {
								cache::set('energyManager::ecs_force::' . $e->getId(), date('Ymd'));
							}
						}
						log::add('energyManager', 'info', 'Complément EDF du ballon validé par l\'utilisateur.');
					}
					break;
			}
			return;
		}

		if ($id === 'force_edf') {
			cache::set('energyManager::ecs_force::' . $eq->getId(), date('Ymd'));
			return;
		}
		if ($type === 'tesla') {
			// teslasmartcharge : set_mode attend select = Solaire | Stand-By | Non actif
			$map = array('mode_solar' => 'Solaire', 'mode_standby' => 'Stand-By', 'mode_inactive' => 'Non actif');
			if (isset($map[$id])) {
				$target = energyManager::resolveCmd($eq->getConfiguration('cmd_set_mode'));
				if ($target === null) {
					throw new Exception(__('Commande set_mode de teslasmartcharge non configurée', __FILE__));
				}
				$target->execCmd(array('select' => $map[$id]));
				$eq->checkAndUpdateCmd('mode_info', $map[$id]);
			}
			return;
		}
		$modes = array('mode_auto' => 'auto', 'mode_on' => 'on', 'mode_off' => 'off');
		if (isset($modes[$id])) {
			$eq->setConfiguration('mode', $modes[$id]);
			$eq->save(true);
			$eq->checkAndUpdateCmd('mode_info', $modes[$id]);
		}
	}
}
