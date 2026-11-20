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

	/** Mémo de la configuration du plugin, valable le temps d'un cycle (une seule requête au lieu d'une par paramètre). */
	private static $cfgMemo = null;

	/** Lecture d'un paramètre du plugin avec valeur par défaut (même si la clé est vide). */
	public static function cfg($_key, $_default = '') {
		if (is_array(self::$cfgMemo)) {
			$v = array_key_exists($_key, self::$cfgMemo) ? self::$cfgMemo[$_key] : '';
		} else {
			$v = config::byKey($_key, 'energyManager', '');
		}
		return ($v === '' || $v === null) ? $_default : $v;
	}

	/** Charge toute la configuration du plugin en mémoire pour la durée d'un cycle ; false si la lecture est impossible. */
	private static function loadCfgMemo() {
		self::$cfgMemo = null;
		try {
			$rows = DB::Prepare('SELECT `key`, `value` FROM `config` WHERE `plugin` = :plugin', array('plugin' => 'energyManager'), DB::FETCH_TYPE_ALL);
			if (!is_array($rows)) {
				return false;
			}
			$m = array();
			foreach ($rows as $r) {
				$v = $r['value'];
				if (is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) {
					$d = json_decode($v, true);
					if (is_array($d)) {
						$v = $d;
					}
				}
				$m[$r['key']] = $v;
			}
			self::$cfgMemo = $m;
			return true;
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Lecture de la configuration : ' . $e->getMessage());
			return false;
		}
	}

	/** Exécute une étape du cycle en isolant ses erreurs : une étape en échec n'empêche pas les suivantes. */
	private static function guard($_label, $_fn) {
		try {
			$_fn();
			return true;
		} catch (Throwable $e) {
			log::add('energyManager', 'error', $_label . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			return false;
		}
	}

	/** Dernière valeur envoyée à un équipement de limite (repli quand le retour d'information n'est pas configuré). */
	private static function lastSent($_name, $_default) {
		$v = cache::byKey('energyManager::last_sent::' . $_name)->getValue('');
		return is_numeric($v) ? (float) $v : (float) $_default;
	}

	/** Échappe une valeur pour l'insérer dans une chaîne JavaScript entre apostrophes ou guillemets. */
	private static function jsStr($_v) {
		$j = json_encode((string) $_v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return ($j === false || strlen($j) < 2) ? '' : substr($j, 1, -1);
	}

	/** Résout une commande depuis sa forme humaine (#[Objet][Equipement][Commande]#). */
	public static function resolveCmd($_human) {
		if (!is_string($_human) || trim($_human) === '') {
			return null;
		}
		try {
			$cmd = cmd::byString($_human);
		} catch (Throwable $e) {
			return null;
		}
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
		try {
			$value = $cmd->execCmd();
		} catch (Throwable $e) {
			return null;
		}
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
		} catch (Throwable $e) {
			// Throwable : attrape aussi les erreurs PHP (TypeError...), sinon le démon s'arrête sans rien dire
			log::add('energyManager', 'error', 'Cycle en erreur : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			try {
				self::sendAlert('cycle_error', 'critical', 'Cycle du démon en erreur : ' . $e->getMessage(), time());
			} catch (Throwable $e2) {
			}
		}
	}

	/* ************************** Cycle de décision ********************* */

	/**
	 * Un cycle complet : lire, décider, appliquer (sauf simulation), alerter.
	 * @param bool $_dry true : ne rien envoyer (utilisé par le simulateur côté serveur)
	 */
	public static function runCycle($_dry = false) {
		$cfgOk = self::loadCfgMemo(); // la configuration est lue une seule fois pour tout le cycle
		try {
			$now = time();
			$staleAge = (int) self::cfg('stale_age', 300);
			$stale = array();
			$ignored = array();
			// configuration illisible : on ne sait pas si la simulation est voulue, donc aucun ordre
			$simulation = $_dry || !$cfgOk || (int) self::cfg('simulation', 1) === 1;
			if (!$cfgOk) {
				self::guard('Alerte configuration', function () use ($now) {
					self::sendAlert('cfg_read', 'critical', 'Lecture de la configuration impossible : cycle exécuté en simulation (aucun ordre).', $now);
				});
			}

			$minute = (int) date('G', $now) * 60 + (int) date('i', $now);
			$hcI = self::hcInfo($minute);
			$slot = $hcI['slot'];
			$hcAfternoon = $hcI['afternoon'];
			$hcAfternoonEnd = $hcI['afternoon_end'];

			$state = array(
				'now' => $now, 'minute' => $minute,
				'grid' => self::measure('cmd_grid', $stale, $staleAge),
				'pv' => self::measure('cmd_pv', $stale, $staleAge),
				// le SOC bouge peu : pas de contrôle d'âge par défaut (stale_age_soc = 0), seule son absence bloque
				'soc' => self::measure('cmd_soc', $stale, (int) self::cfg('stale_age_soc', 0)),
				// batterie et maison sont informatives : leur absence ne bloque pas le pilotage
				'batt' => self::measure('cmd_batt', $ignored, $staleAge),
				'house' => self::measure('cmd_house', $ignored, $staleAge),
				'stale' => $stale,
				'hc' => ($slot === 'HC'), 'hc_afternoon' => $hcAfternoon,
				'cfg' => self::engineConfig(),
				'equips' => array(),
				'mem' => self::cacheJson('energyManager::mem'),
			);
			// seuls les équipements "pilotés" (voyant vert) sont confiés au moteur ; les autres restent juste mesurés
			$state['all_equips'] = self::buildEquips($now, $minute, $hcAfternoonEnd);
			foreach ($state['all_equips'] as $e) {
				if ($e['piloted']) {
					$state['equips'][] = $e;
				}
			}
			$active = ((int) self::cfg('pilot_active', 1) === 1);
			try {
				self::learnNight($state, $now, $minute); // apprentissage de la consommation de nuit (indépendant du pilotage)
			} catch (Throwable $e) {
				log::add('energyManager', 'error', 'Apprentissage de la nuit : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			}
			$fc = self::readCmd(self::cfg('cmd_forecast_kwh'));
			$state['forecast_kwh'] = ($fc !== null && is_numeric($fc['value'])) ? (float) $fc['value'] : null;
			// retour d'information absent ou illisible : on se replie sur la dernière valeur réellement envoyée (sinon le maximum)
			$inv = self::readCmd(self::cfg('cmd_inv_limit_fb'));
			$state['inv_limit'] = ($inv !== null && is_numeric($inv['value'])) ? (float) $inv['value'] : self::lastSent('inv', $state['cfg']['inv_max']);
			$hoy = self::readCmd(self::cfg('cmd_hoy_limit_fb'));
			$chg = self::readCmd(self::cfg('cmd_charge_limit_fb'));
			$state['hoy_limit'] = ($hoy !== null && is_numeric($hoy['value'])) ? (float) $hoy['value'] : self::lastSent('hoy', $state['cfg']['hoy_max']);
			$state['charge_limit'] = ($chg !== null && is_numeric($chg['value'])) ? (float) $chg['value'] : self::lastSent('charge', $state['cfg']['charge_max']);

			if (!$active) {
				// interrupteur général rouge : on mesure et on affiche, mais aucune décision, aucun ordre, aucune alerte
				$result = array('actions' => array(), 'alerts' => array(), 'mem' => array(),
					'info' => array('decision' => 'Pilotage désactivé (interrupteur rouge)'));
				cache::set('energyManager::mem', '{}');
				if (!$simulation) {
					self::guard('Retour à l\'état sûr', function () use ($state, $now) {
						self::safeState($state, $now);
					});
				}
				self::guard('Recopie des mesures', function () use ($state, $result, $slot, $simulation) {
					self::mirror($state, $result, $slot, $simulation);
				});
				self::guard('Alertes', function () use ($now) {
					self::handleAlerts(array(), $now);
				});
				return $result;
			}
			if ((int) cache::byKey('energyManager::safe_done')->getValue(0) === 1) {
				cache::set('energyManager::safe_done', 0); // pilotage réactivé : le prochain passage au rouge refera le retour à l'état sûr
			}

			$result = energyManagerEngine::decide($state);
			cache::set('energyManager::mem', json_encode($result['mem']));

			// post-traitement ballon : thermostat atteint = consommation retombée à zéro
			self::guard('Surveillance du thermostat du ballon', function () use ($state, $now, $simulation, &$result) {
				self::ecsThermostatWatch($state['equips'], $now, $simulation, $result);
			});

			// en simulation rien n'est envoyé, donc le même ordre revient à chaque cycle : on ne le journalise que toutes les 5 minutes
			$simlog = self::cacheJson('energyManager::simlog');
			foreach ($simlog as $sk => $st) {
				if (!is_numeric($st) || ($now - (int) $st) >= 300) {
					unset($simlog[$sk]);
				}
			}
			foreach ($result['actions'] as $a) {
				if ($simulation && $a['type'] === 'tesla_mode') {
					$sk = 'energyManager::tesla_sim_logged::' . $a['id'];
					if ((int) cache::byKey($sk)->getValue(0) === 1) {
						continue;
					}
					cache::set($sk, 1);
				}
				$line = self::describeAction($a);
				if ($simulation) {
					$sig = md5($a['type'] . '|' . (isset($a['id']) ? $a['id'] : '') . '|' . (isset($a['cmd']) ? $a['cmd'] : '') . '|' . (isset($a['value']) ? $a['value'] : ''));
					if (isset($simlog[$sig])) {
						continue;
					}
					$simlog[$sig] = $now;
				}
				log::add('energyManager', $simulation ? 'info' : 'debug', ($simulation ? '[SIMULATION] ' : '') . $line);
				if (!$simulation) {
					try {
						self::applyAction($a, $now);
					} catch (Throwable $e) {
						log::add('energyManager', 'error', 'Ordre en échec : ' . $line . ' : ' . $e->getMessage());
						self::sendAlert('order_' . $a['type'] . (isset($a['id']) ? '_' . $a['id'] : ''), 'critical', 'Ordre non exécuté (' . $line . ') : ' . $e->getMessage(), $now);
					}
				}
			}
			if ($simulation) {
				cache::set('energyManager::simlog', json_encode($simlog));
			}
			if (!$simulation) {
				self::guard('Priorité Tesla', function () {
					self::syncTeslaPriority();
				});
				self::guard('Seuil Tesla', function () {
					self::syncTeslaThreshold();
				});
			}
			self::guard('Vérification des retours', function () use ($now) {
				self::checkFeedback($now);
			});
			if (!$simulation) {
				// la question Discord est un ordre : pas en simulation
				self::guard('Question Discord du ballon', function () use ($state, $minute, $hcAfternoonEnd) {
					self::ecsAsk($state['equips'], $minute, $hcAfternoonEnd);
				});
			}
			self::guard('Recopie des mesures', function () use ($state, $result, $slot, $simulation) {
				self::mirror($state, $result, $slot, $simulation);
			});
			self::guard('Alertes', function () use ($result, $now) {
				self::handleAlerts($result['alerts'], $now);
			});
			return $result;
		} finally {
			self::$cfgMemo = null;
		}
	}

	/**
	 * Créneau tarifaire à une minute du jour (0-1439) d'après les plages d'heures creuses configurées.
	 * @return array slot ('HC'|'HP'), afternoon (bool : en HC d'après-midi), afternoon_end (minute de fin de l'HC d'après-midi ou null)
	 */
	public static function hcInfo($_minute) {
		$slots = energyManagerEngine::parseSlots(self::cfg('hc_slots', ''));
		$out = array('slot' => energyManagerEngine::slotAt($slots, $_minute), 'afternoon' => false, 'afternoon_end' => null);
		foreach ($slots as $sl) {
			if ($sl[2] === 'HC' && $sl[0] >= 720 && $sl[0] < 1080) {
				$out['afternoon_end'] = $sl[1];
				if ($_minute >= $sl[0] && $_minute < $sl[1]) {
					$out['afternoon'] = true;
				}
			}
		}
		return $out;
	}

	/* ************ Équipement que l'on cesse (ou recommence) de piloter ************ */

	/**
	 * Un équipement passe en « non piloté » : on l'éteint proprement avant de le laisser tranquille.
	 *  - radiateur, ballon : commande OFF ;
	 *  - Tesla : « Stand-By » (qui arrête la charge en cours) puis « Non actif » (teslasmartcharge ne fait plus rien) ;
	 *  - climatisation : prévu avec le pilotage de la clim.
	 * En simulation, rien n'est envoyé (l'intention est seulement journalisée).
	 */
	public static function releaseEquipment($_eq) {
		$type = $_eq->getConfiguration('eqType');
		$name = $_eq->getName();
		$sim = ((int) self::cfg('simulation', 1) === 1);
		try {
			if ($type === 'tesla') {
				$mc = self::resolveCmd($_eq->getConfiguration('cmd_set_mode'));
				if ($mc === null) {
					log::add('energyManager', 'warning', $name . ' : commande set_mode introuvable, la Tesla n\'a pas pu être mise sur Non actif.');
					return;
				}
				if ($sim) {
					log::add('energyManager', 'info', '[SIMULATION] ' . $name . ' non piloté : Stand-By (arrêt de la charge) puis Non actif.');
					return;
				}
				$mc->execCmd(array('select' => 'Stand-By'));
				$mc->execCmd(array('select' => 'Non actif'));
				$_eq->checkAndUpdateCmd('mode_info', 'Non actif');
				cache::set('energyManager::tesla_plug_handled::' . $_eq->getId(), 0);
				log::add('energyManager', 'info', $name . ' non piloté : charge arrêtée, teslasmartcharge sur Non actif.');
			} elseif ($type === 'simple' || $type === 'ecs') {
				$off = self::resolveCmd($_eq->getConfiguration('cmd_off'));
				if ($off === null) {
					log::add('energyManager', 'warning', $name . ' : commande OFF introuvable, l\'équipement n\'a pas pu être éteint.');
					return;
				}
				if ($sim) {
					log::add('energyManager', 'info', '[SIMULATION] ' . $name . ' non piloté : mise à OFF.');
					return;
				}
				$off->execCmd();
				log::add('energyManager', 'info', $name . ' non piloté : mis à OFF.');
			}
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Extinction de ' . $name . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}

	/** Un équipement redevient piloté : une Tesla laissée sur « Non actif » repasse en Stand-By (le passage en Solaire est repris au branchement). */
	public static function engageEquipment($_eq) {
		if ($_eq->getConfiguration('eqType') !== 'tesla' || ((int) self::cfg('simulation', 1) === 1)) {
			return;
		}
		try {
			$m = self::readCmd($_eq->getConfiguration('cmd_mode_info'));
			$mc = self::resolveCmd($_eq->getConfiguration('cmd_set_mode'));
			if ($m !== null && $mc !== null && (string) $m['value'] === 'Non actif') {
				$mc->execCmd(array('select' => 'Stand-By'));
				$_eq->checkAndUpdateCmd('mode_info', 'Stand-By');
				cache::set('energyManager::tesla_plug_handled::' . $_eq->getId(), 0);
				log::add('energyManager', 'info', $_eq->getName() . ' de nouveau piloté : teslasmartcharge repasse en Stand-By.');
			}
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Remise en service de ' . $_eq->getName() . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}

	/* ******************** Réserve de nuit apprise ******************** */

	/** Historique des consommations de nuit (kWh), texte "5.2;4.8;..." (jamais du JSON : Jeedom le décoderait). */
	public static function nightHistory() {
		$raw = self::cfg('night_history', '');
		if (is_array($raw)) {
			$raw = implode(';', $raw);
		}
		$out = array();
		foreach (explode(';', (string) $raw) as $v) {
			if (is_numeric(trim($v)) && (float) $v > 0) {
				$out[] = (float) $v;
			}
		}
		return $out;
	}

	public static function nightNeedKwh() {
		return energyManagerEngine::nightNeed(self::nightHistory(), (float) self::cfg('night_kwh_default', 6));
	}

	public static function reserveAuto() {
		return ((int) self::cfg('reserve_auto', 1) === 1);
	}

	/** Réserve de nuit appliquée (%) : calculée d'après les nuits mesurées, ou réglée à la main avec la jauge. */
	public static function effectiveReserve() {
		if (!self::reserveAuto()) {
			return (float) self::cfg('night_reserve', 50);
		}
		return energyManagerEngine::reserveFromNeed(self::nightNeedKwh(), array(
			'soc_min' => (float) self::cfg('soc_min', 10), 'batt_kwh' => (float) self::cfg('batt_kwh', 16),
			'night_margin' => (float) self::cfg('night_margin', 15),
		));
	}

	/** Texte affiché dans le widget : d'où vient la réserve. */
	public static function reserveText() {
		$r = round(self::effectiveReserve());
		if (!self::reserveAuto()) {
			return 'manuelle : ' . $r . ' %';
		}
		$n = count(self::nightHistory());
		$need = number_format(self::nightNeedKwh(), 1, ',', '');
		$t = 'auto : ' . $r . ' % (' . ($n >= 3
			? 'consommation de nuit apprise ' . $need . ' kWh sur ' . $n . ' nuits'
			: 'apprentissage ' . $n . '/3 nuits, valeur par défaut ' . $need . ' kWh') . ')';
		if ((int) cache::byKey('energyManager::night_on')->getValue(0) === 1) {
			$t .= ' · nuit en cours : ' . number_format((float) cache::byKey('energyManager::night_wh')->getValue(0) / 1000, 1, ',', '') . ' kWh';
		}
		return $t;
	}

	/**
	 * Mesure la consommation de la maison pendant la nuit (de la tombée du jour au lever du soleil) et l'ajoute à l'historique.
	 * Consommation = puissance « maison » moins les équipements pilotés (sinon la décharge de la batterie), cycle après cycle.
	 */
	private static function learnNight(array $_state, $_now, $_minute) {
		$pv = $_state['pv'];
		if ($pv === null) {
			return;
		}
		$managed = 0.0;
		foreach ($_state['all_equips'] as $e) {
			$managed += (float) $e['power_real'];
		}
		if ($_state['house'] !== null) {
			$w = max(0, (float) $_state['house'] - $managed);
		} elseif ($_state['batt'] !== null) {
			$w = max(0, -(float) $_state['batt']);
		} else {
			return;
		}
		$last = (int) cache::byKey('energyManager::night_ts')->getValue(0);
		cache::set('energyManager::night_ts', $_now);
		$dt = ($last > 0) ? min(120, max(0, $_now - $last)) : 0;
		$minPv = (float) self::cfg('min_pv', 300);
		$on = ((int) cache::byKey('energyManager::night_on')->getValue(0) === 1);
		if (!$on) {
			if ($pv < $minPv && $_minute >= 17 * 60) {
				cache::set('energyManager::night_on', 1);
				cache::set('energyManager::night_wh', 0);
				cache::set('energyManager::night_sec', 0);
				cache::set('energyManager::night_start', $_now);
			}
			return;
		}
		$start = (int) cache::byKey('energyManager::night_start')->getValue(0);
		if (($_now - $start) > 20 * 3600) { // nuit jamais terminée (plugin arrêté) : on l'abandonne
			cache::set('energyManager::night_on', 0);
			return;
		}
		$wh = (float) cache::byKey('energyManager::night_wh')->getValue(0) + $w * $dt / 3600;
		$sec = (int) cache::byKey('energyManager::night_sec')->getValue(0) + $dt;
		cache::set('energyManager::night_wh', $wh);
		cache::set('energyManager::night_sec', $sec);
		if ($pv >= $minPv && $_minute >= 4 * 60 && $_minute < 12 * 60) {
			// lever du soleil : la nuit est terminée. On ne retient que les nuits complètes et bien mesurées.
			cache::set('energyManager::night_on', 0);
			$duration = $_now - $start;
			if ($duration >= 4 * 3600 && $sec >= 0.8 * $duration && $wh > 300) {
				$h = self::nightHistory();
				$h[] = round($wh / 1000, 2);
				$h = array_slice($h, -14);
				config::save('night_history', implode(';', $h), 'energyManager');
				if (is_array(self::$cfgMemo)) {
					self::$cfgMemo['night_history'] = implode(';', $h);
				}
				log::add('energyManager', 'info', 'Nuit mesurée : ' . round($wh / 1000, 2) . ' kWh sur ' . round($duration / 3600, 1) . ' h. Réserve de nuit : ' . self::reserveText());
			} else {
				log::add('energyManager', 'info', 'Nuit ignorée (trop courte ou mal mesurée) : ' . round($wh / 1000, 2) . ' kWh, ' . round($duration / 3600, 1) . ' h, mesures sur ' . round($sec / 3600, 1) . ' h.');
			}
		}
	}

	/**
	 * Retour à l'état sûr au passage de l'interrupteur au rouge (une seule fois) : les limites que le plugin a pu réduire
	 * (micro-onduleurs Hoymiles, onduleur Victron, charge de la batterie) reviennent à leur valeur maximale.
	 */
	private static function safeState(array $_state, $_now) {
		if ((int) cache::byKey('energyManager::safe_done')->getValue(0) === 1) {
			return;
		}
		// pas plus d'une tentative par minute (si un ordre échoue, on réessaie au cycle suivant après ce délai)
		if (time() - (int) cache::byKey('energyManager::safe_try')->getValue(0) < 60) {
			return;
		}
		cache::set('energyManager::safe_try', time());
		$ok = true;
		$cfg = $_state['cfg'];
		$todo = array(
			array('type' => 'hoymiles', 'value' => $cfg['hoy_max'], 'cur' => $_state['hoy_limit']),
			array('type' => 'inv_limit', 'value' => $cfg['inv_max'], 'cur' => $_state['inv_limit']),
			array('type' => 'charge_limit', 'value' => $cfg['charge_max'], 'cur' => $_state['charge_limit']),
		);
		foreach ($todo as $a) {
			if ((float) $a['cur'] < (float) $a['value'] - 5) {
				$a['reason'] = 'pilotage désactivé : limite remise à sa valeur maximale';
				log::add('energyManager', 'info', self::describeAction($a));
				try {
					if (self::applyAction($a, $_now) === false) {
						$ok = false; // commande de pilotage absente : rien n'a été remis au maximum, on réessaiera
					}
				} catch (Throwable $e) {
					$ok = false;
					log::add('energyManager', 'error', 'Retour à l\'état sûr : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
				}
			}
		}
		if ($ok) {
			cache::set('energyManager::safe_done', 1);
		}
	}

	/**
	 * Remet les limites que le plugin a pu réduire (Hoymiles, onduleur Victron, charge batterie) à leur valeur maximale.
	 * Utilisé au passage en simulation : ordre unique et volontaire, car ensuite plus aucun ordre ne partira.
	 */
	public static function restoreLimits($_reason) {
		$cfg = self::engineConfig();
		$todo = array(
			array('type' => 'hoymiles', 'value' => $cfg['hoy_max'], 'fb' => 'cmd_hoy_limit_fb', 'name' => 'hoy'),
			array('type' => 'inv_limit', 'value' => $cfg['inv_max'], 'fb' => 'cmd_inv_limit_fb', 'name' => 'inv'),
			array('type' => 'charge_limit', 'value' => $cfg['charge_max'], 'fb' => 'cmd_charge_limit_fb', 'name' => 'charge'),
		);
		foreach ($todo as $a) {
			try {
				$fb = self::readCmd(self::cfg($a['fb']));
				$cur = ($fb !== null && is_numeric($fb['value'])) ? (float) $fb['value'] : self::lastSent($a['name'], $a['value']);
				if ($cur < (float) $a['value'] - 5) {
					$a['reason'] = $_reason;
					log::add('energyManager', 'info', self::describeAction($a));
					self::applyAction($a, time());
				}
			} catch (Throwable $e) {
				log::add('energyManager', 'error', 'Remise des limites : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			}
		}
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
			'night_reserve' => self::effectiveReserve(),
			'battery_threshold' => (float) self::cfg('battery_threshold', 80),
			'ecs_deadline' => (float) self::cfg('ecs_deadline', 16) * 60,
			'ecs_absorb_temp' => (float) self::cfg('ecs_absorb_temp', 50),
			'evening' => (float) self::cfg('evening_hour', 19) * 60,
			'talon' => (float) self::cfg('talon', 400),
			'batt_kwh' => (float) self::cfg('batt_kwh', 16),
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
			// la Tesla a ses propres champs (clés distinctes de celles du ballon et des radiateurs, sinon Jeedom écrase l'un par l'autre)
			$isTesla = ($type === 'tesla');
			$powerCmd = $eq->getConfiguration($isTesla ? 'tesla_cmd_power' : 'cmd_power');
			if ($isTesla && trim((string) $powerCmd) === '') {
				$powerCmd = $eq->getConfiguration('cmd_power');
			}
			$theo = $eq->getConfiguration($isTesla ? 'tesla_power_max' : 'power_theo');
			if ($isTesla && ($theo === '' || $theo === null)) {
				$theo = $eq->getConfiguration('power_theo');
			}
			$theo = (is_numeric($theo) && (float) $theo > 0) ? (float) $theo : ($isTesla ? 7200 : 1000);
			$pr = self::readCmd($powerCmd);
			$st = self::readCmd($eq->getConfiguration('cmd_state'));
			$power = ($pr !== null && is_numeric($pr['value'])) ? (float) $pr['value'] : 0;
			$zero = (float) $eq->getConfiguration('zero_threshold', 20);
			$isOn = null;
			if ($st !== null) {
				$sv = $st['value'];
				if (is_bool($sv)) {
					$isOn = $sv;
				} elseif (is_numeric($sv)) {
					$isOn = ((float) $sv > 0);
				} elseif (is_string($sv)) {
					$sl = mb_strtolower(trim($sv));
					if (in_array($sl, array('on', 'true'), true)) {
						$isOn = true;
					} elseif (in_array($sl, array('off', 'false'), true)) {
						$isOn = false;
					}
				}
			}
			if ($isOn === null) {
				$isOn = ($power > $zero);
			}
			$e = array(
				'id' => $eq->getId(), 'type' => $type, 'name' => $eq->getName(),
				'mode' => $eq->getConfiguration('mode', 'auto'),
				'piloted' => ((int) $eq->getConfiguration('piloted', 1) === 1),
				'is_on' => $isOn, 'power_real' => $power,
				'power_theo' => $theo,
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
				$e['min_temp'] = (float) $eq->getConfiguration('min_temp', 50);
				$e['done_today'] = ((string) cache::byKey('energyManager::ecs_done::' . $eq->getId())->getValue('') === date('Ymd'));
				$e['force_edf'] = ((string) cache::byKey('energyManager::ecs_force::' . $eq->getId())->getValue('') === date('Ymd'));
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
				$e['plugged'] = self::teslaPlugged($eq);
				$e['margin'] = self::teslaMargin($eq);
				$aggr = array('small' => 500, 'medium' => 1000, 'large' => 2000);
				$ag = $eq->getConfiguration('aggressiveness', 'medium');
				$e['aggr_w'] = isset($aggr[$ag]) ? $aggr[$ag] : 1000;
				$e['plug_handled'] = ((int) cache::byKey('energyManager::tesla_plug_handled::' . $eq->getId())->getValue(0) === 1);
				if ($e['plugged'] === false) {
					// débranchée : le prochain branchement sera de nouveau pris en charge (on n'écrit que si nécessaire)
					foreach (array('energyManager::tesla_plug_handled::', 'energyManager::tesla_sim_logged::') as $ck) {
						if ((int) cache::byKey($ck . $eq->getId())->getValue(0) !== 0) {
							cache::set($ck . $eq->getId(), 0);
						}
					}
					$e['plug_handled'] = false;
				}
			}
			$list[] = $e;
		}
		return $list;
	}

	/**
	 * Commande info liée à la Tesla : champ facultatif de l'équipement, sinon détection automatique via la commande set_mode
	 * (équipement teslasmartcharge) : commande « $_tscLogical » de teslasmartcharge ou « $_teslaLogical » de l'équipement Tesla.
	 */
	private static function teslaInfoCmd($_eq, $_cfgKey, $_tscLogical, $_teslaLogical = '') {
		$cmd = self::resolveCmd($_eq->getConfiguration($_cfgKey));
		if ($cmd !== null) {
			return $cmd;
		}
		$mode = self::resolveCmd($_eq->getConfiguration('cmd_set_mode'));
		$tsc = ($mode !== null) ? $mode->getEqLogic() : null;
		if (!is_object($tsc)) {
			return null;
		}
		if ($_tscLogical !== '') {
			$c = $tsc->getCmd(null, $_tscLogical);
			if (is_object($c)) {
				return $c;
			}
		}
		if ($_teslaLogical !== '') {
			$teslaEq = eqLogic::byId($tsc->getConfiguration('tesla_eqlogic_id'));
			if (is_object($teslaEq)) {
				$c = $teslaEq->getCmd(null, $_teslaLogical);
				if (is_object($c)) {
					return $c;
				}
			}
		}
		return null;
	}

	/**
	 * La voiture est-elle branchée ? Lit « charge_port_latch » du plugin Tesla.
	 * @return bool|null null si impossible à déterminer
	 */
	public static function teslaPlugged($_eq) {
		$cmd = self::teslaInfoCmd($_eq, 'cmd_plugged', '', 'charge_port_latch');
		if (!is_object($cmd)) {
			return null;
		}
		$v = mb_strtolower(trim((string) $cmd->execCmd()));
		if ($v === '') {
			return null;
		}
		return in_array($v, array('engagé', 'engage', 'engaged', 'connected', 'plugged', '1', 'true'), true);
	}

	/** Marge de teslasmartcharge (W, -300 à +300) : négative = soutirage EDF toléré. 0 si inconnue. */
	public static function teslaMargin($_eq) {
		$cmd = self::teslaInfoCmd($_eq, 'cmd_margin', 'marge_actuelle');
		$v = is_object($cmd) ? $cmd->execCmd() : null;
		return is_numeric($v) ? (float) $v : 0.0;
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
			} catch (Throwable $e) {
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
			if ($e['type'] !== 'ecs' || $e['mode'] !== 'auto' || $e['force_edf']) {
				continue;
			}
			if ($e['water_temp'] === null || $e['water_temp'] >= $e['min_temp']) {
				continue;
			}
			$k = 'energyManager::ecs_asked::' . $e['id'];
			if ((string) cache::byKey($k)->getValue('') === date('Ymd')) {
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
				} catch (Throwable $ex) {
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
			case 'inv_limit':
				return 'Limite onduleur Victron → ' . $a['value'] . ' W : ' . $a['reason'];
			case 'charge_limit':
				return 'Limite de charge batterie → ' . $a['value'] . ' W : ' . $a['reason'];
			case 'tesla_mode':
				$te = eqLogic::byId($a['id']);
				return (is_object($te) ? $te->getName() : '#' . $a['id']) . ' → mode ' . $a['value'] . ' : ' . $a['reason'];
		}
		return json_encode($a);
	}

	private static function applyAction(array $a, $_now) {
		switch ($a['type']) {
			case 'equip':
				$eq = eqLogic::byId($a['id']);
				if (!is_object($eq)) {
					return false;
				}
				$cmd = self::resolveCmd($eq->getConfiguration($a['cmd'] === 'on' ? 'cmd_on' : 'cmd_off'));
				if ($cmd === null) {
					self::sendAlert('missing_cmd_' . $a['type'] . '_' . $a['id'], 'critical', 'Commande ' . $a['cmd'] . ' introuvable pour « ' . $eq->getName() . ' ».', $_now);
					return false;
				}
				$cmd->execCmd();
				cache::set('energyManager::last_order::' . $a['id'], json_encode(array('cmd' => $a['cmd'], 't' => $_now)));
				$eq->checkAndUpdateCmd('last_reason', $a['reason']);
				break;
			case 'tesla_mode':
				$te = eqLogic::byId($a['id']);
				if (!is_object($te)) {
					return false;
				}
				$mc = self::resolveCmd($te->getConfiguration('cmd_set_mode'));
				if ($mc === null) {
					self::sendAlert('missing_cmd_' . $a['type'] . '_' . $a['id'], 'critical', 'Commande set_mode de teslasmartcharge introuvable pour « ' . $te->getName() . ' ».', $_now);
					return false;
				}
				$mc->execCmd(array('select' => $a['value']));
				cache::set('energyManager::tesla_plug_handled::' . $a['id'], 1);
				$te->checkAndUpdateCmd('mode_info', $a['value']);
				break;
			case 'hoymiles':
				return self::sendSlider('cmd_hoy_limit', $a['value'], 'hoy', $_now);
			case 'inv_limit':
				return self::sendSlider('cmd_inv_limit', $a['value'], 'inv', $_now);
			case 'charge_limit':
				return self::sendSlider('cmd_charge_limit', $a['value'], 'charge', $_now);
		}
		return true;
	}

	private static function sendSlider($_cfgKey, $_value, $_fbName, $_now) {
		$cmd = self::resolveCmd(self::cfg($_cfgKey));
		if ($cmd === null) {
			self::sendAlert('missing_cmd_' . $_cfgKey, 'critical', 'Commande de pilotage « ' . $_cfgKey . ' » non configurée.', $_now);
			return false;
		}
		$cmd->execCmd(array('slider' => $_value));
		cache::set('energyManager::last_sent::' . $_fbName, $_value);
		cache::set('energyManager::pending::' . $_fbName, json_encode(array('value' => $_value, 't' => $_now)));
		return true;
	}

	/**
	 * Envoie à teslasmartcharge la priorité choisie dans energyManager (voiture ou maison).
	 * Ne renvoie l'ordre que si la valeur a changé (ou si la Tesla vient d'être ajoutée).
	 */
	public static function syncTeslaPriority() {
		if ((int) self::cfg('pilot_active', 1) !== 1) {
			return; // pilotage général désactivé : on n'envoie rien à teslasmartcharge
		}
		$wanted = (self::cfg('tesla_priority', 'tesla') === 'tesla') ? 'voiture' : 'maison';
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			if ($eq->getConfiguration('eqType') !== 'tesla' || (int) $eq->getConfiguration('piloted', 1) !== 1) {
				continue;
			}
			$key = 'energyManager::tesla_prio::' . $eq->getId();
			if ((string) cache::byKey($key)->getValue('') === $wanted) {
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

	/**
	 * Envoie à teslasmartcharge le seuil de batterie choisi dans le widget (commande set_seuil_batterie_maison),
	 * seulement s'il a changé : energyManager est le seul décideur de la priorité et de son seuil.
	 */
	public static function syncTeslaThreshold() {
		if ((int) self::cfg('pilot_active', 1) !== 1) {
			return;
		}
		$wanted = (int) round((float) self::cfg('battery_threshold', 80));
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			if ($eq->getConfiguration('eqType') !== 'tesla' || (int) $eq->getConfiguration('piloted', 1) !== 1) {
				continue;
			}
			$key = 'energyManager::tesla_thr::' . $eq->getId();
			if ((string) cache::byKey($key)->getValue('') === (string) $wanted) {
				continue;
			}
			$cmd = self::teslaInfoCmd($eq, 'cmd_set_threshold', 'set_seuil_batterie_maison');
			if ($cmd === null) {
				continue;
			}
			$cmd->execCmd(array('slider' => $wanted));
			cache::set($key, (string) $wanted);
			log::add('energyManager', 'info', 'Seuil de batterie envoyé à teslasmartcharge : ' . $wanted . ' %');
		}
	}

	/** Les ordres Victron / Ahoy doivent être confirmés par leur retour d'information. */
	private static function checkFeedback($_now) {
		$map = array('hoy' => 'cmd_hoy_limit_fb', 'charge' => 'cmd_charge_limit_fb', 'inv' => 'cmd_inv_limit_fb');
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
			if (!empty($al['once'])) {
				// alerte unique par jour (ex. ballon non chaud à l'échéance) : pas de boucle
				$k = 'energyManager::once::' . $al['key'];
				if ((string) cache::byKey($k)->getValue('') !== date('Ymd')) {
					cache::set($k, date('Ymd'));
					self::notify($al['level'], $al['message']);
				}
				continue;
			}
			self::sendAlert($al['key'], $al['level'], $al['message'], $_now);
		}
		foreach (array('stale', 'incoherent', 'injection', 'injection_legal') as $k) {
			// réarmement antirebond : une alerte n'est réarmée qu'après alert_rearm secondes (120) sans réapparaître,
			// pour éviter qu'un problème qui clignote ne renvoie un message Discord à chaque va-et-vient
			$ck = 'energyManager::alert_clear::' . $k;
			if (isset($active[$k])) {
				if ((int) cache::byKey($ck)->getValue(0) !== 0) {
					cache::set($ck, 0);
				}
				continue;
			}
			$since = (int) cache::byKey($ck)->getValue(0);
			if ($since === 0) {
				cache::set($ck, $_now);
			} elseif (($_now - $since) >= (int) self::cfg('alert_rearm', 120)) {
				if ((int) cache::byKey('energyManager::alert::' . $k)->getValue(0) !== 0) {
					cache::set('energyManager::alert::' . $k, 0);
				}
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

	/** Mention Discord de l'utilisateur : accepte l'identifiant seul ou la forme <@identifiant>. */
	public static function discordMention() {
		$v = trim((string) self::cfg('discord_user_id', ''));
		if (preg_match('/(\d{5,})/', $v, $m)) {
			return '<@' . $m[1] . '>';
		}
		return '';
	}

	/** Message Jeedom + webhook Discord d'urgence. */
	public static function notify($_level, $_message) {
		log::add('energyManager', $_level === 'critical' ? 'error' : 'warning', $_message);
		try {
			message::add('energyManager', $_message);
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Message Jeedom impossible : ' . $e->getMessage());
		}
		$hook = trim((string) self::cfg('discord_webhook', ''));
		if ($hook === '' || strpos($hook, 'https://discord.com/api/webhooks/') !== 0) {
			return;
		}
		if (!function_exists('curl_init')) {
			log::add('energyManager', 'warning', 'Notification Discord impossible : l\'extension PHP curl est absente.');
			return;
		}
		try {
			$mention = self::discordMention();
			$payload = json_encode(array('content' => ($_level === 'critical' ? '🚨 ' . ($mention !== '' ? $mention . ' ' : '') : '⚠️ ') . '**Energy Manager** : ' . $_message), JSON_INVALID_UTF8_SUBSTITUTE);
			if ($payload === false) {
				log::add('energyManager', 'warning', 'Notification Discord impossible : message non encodable en JSON.');
				return;
			}
			$ch = curl_init($hook);
			curl_setopt_array($ch, array(
				CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
				CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
			));
			curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = curl_error($ch);
			curl_close($ch);
			if ($code < 200 || $code >= 300) {
				log::add('energyManager', 'warning', 'Notification Discord non remise (code HTTP ' . $code . ($err !== '' ? ', ' . $err : '') . ').');
			}
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Notification Discord en échec : ' . $e->getMessage());
		}
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
		$main->checkAndUpdateCmd('inv_limit', $_state['inv_limit']);
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
		$main->checkAndUpdateCmd('active_info', (int) self::cfg('pilot_active', 1));
		$main->checkAndUpdateCmd('threshold_info', (float) self::cfg('battery_threshold', 80));
		$main->checkAndUpdateCmd('reserve_info', self::effectiveReserve());
		$main->checkAndUpdateCmd('reserve_text', self::reserveText());
		$main->checkAndUpdateCmd('reserve_auto_info', self::reserveAuto() ? 1 : 0);
		foreach ($_state['all_equips'] as $e) {
			$eq = eqLogic::byId($e['id']);
			if (!is_object($eq)) {
				continue;
			}
			$eq->checkAndUpdateCmd('piloted_info', $e['piloted'] ? 1 : 0);
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
				array('hoy_limit', 'Limite Hoymiles', 'W'), array('charge_limit', 'Limite charge batterie', 'W'), array('inv_limit', 'Limite onduleur', 'W'),
				array('active_info', 'Pilotage actif', ''), array('reserve_info', 'Réserve de nuit', '%'), array('reserve_auto_info', 'Réserve automatique', ''), array('threshold_info', 'Seuil batterie prioritaire', '%'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'info', 'numeric', $c[2]);
			}
			foreach (array(
				array('decision', 'Décision'), array('slot', 'Créneau tarifaire'), array('season', 'Saison'),
				array('tesla_priority_info', 'Priorité Tesla/Batterie'), array('batt_target_info', 'Cible batterie'),
				array('sim_info', 'Simulation'), array('reserve_text', 'Réserve de nuit (origine)'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'info', 'string');
			}
			foreach (array(
				array('prio_tesla', 'Priorité Tesla'), array('prio_battery', 'Priorité batterie'),
				array('target_95', 'Cible batterie 95 %'), array('target_100', 'Cible batterie 100 %'),
				array('sim_on', 'Simulation ON'), array('sim_off', 'Simulation OFF'),
				array('active_on', 'Pilotage ON'), array('active_off', 'Pilotage OFF'), array('reserve_auto_on', 'Réserve automatique ON'), array('reserve_auto_off', 'Réserve automatique OFF'),
			) as $c) {
				$this->createCmd($c[0], $c[1], 'action', 'other');
			}
			$this->createCmd('ecs_answer', 'Réponse ballon (ask)', 'action', 'message');
			$th = $this->createCmd('threshold_set', 'Définir le seuil batterie prioritaire', 'action', 'slider');
			if ($th->getConfiguration('minValue', '') === '') {
				$th->setConfiguration('minValue', 0);
				$th->setConfiguration('maxValue', 100);
				$th->save();
			}
			$rs = $this->createCmd('reserve_set', 'Définir la réserve de nuit', 'action', 'slider');
			if ($rs->getConfiguration('minValue', '') === '') {
				$rs->setConfiguration('minValue', 0);
				$rs->setConfiguration('maxValue', 100);
				$rs->save();
			}
			return;
		}
		$this->createCmd('power', 'Puissance', 'info', 'numeric', 'W');
		$this->createCmd('status', 'Statut', 'info', 'string');
		$this->createCmd('mode_info', 'Mode', 'info', 'string');
		$this->createCmd('last_reason', 'Dernière décision', 'info', 'string');
		$this->createCmd('piloted_info', 'Piloté', 'info', 'numeric');
		$this->createCmd('pilot_on', 'Piloté ON', 'action', 'other');
		$this->createCmd('pilot_off', 'Piloté OFF', 'action', 'other');
		if ($type === 'tesla') {
			$this->createCmd('mode_solar', 'Mode solaire', 'action', 'other');
			$old = $this->getCmd(null, 'mode_standby');
			if (is_object($old)) {
				$old->remove(); // retirée : Stand-By = voiture débranchée, géré par teslasmartcharge
			}
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
		foreach (array('pv', 'grid', 'batt', 'soc', 'house', 'injection', 'hoy_limit', 'charge_limit', 'inv_limit', 'decision', 'sim_info',
			'tesla_priority_info', 'batt_target_info', 'slot', 'season', 'prio_tesla', 'prio_battery', 'target_95', 'target_100',
			'sim_on', 'sim_off', 'active_info', 'active_on', 'active_off', 'reserve_info', 'reserve_set', 'reserve_auto_info', 'reserve_text', 'reserve_auto_on', 'reserve_auto_off', 'threshold_info', 'threshold_set') as $l) {
			$c = $this->getCmd(null, $l);
			$replace['#' . $l . '_id#'] = is_object($c) ? $c->getId() : 0;
			// seules les commandes INFO sont lues : execCmd() sur une commande ACTION l'EXÉCUTE (priorité, simulation, pilotage...)
			$replace['#' . $l . '_val#'] = (is_object($c) && $c->getType() === 'info') ? self::jsStr($c->execCmd()) : '';
		}
		$consumers = array();
		foreach (eqLogic::byType('energyManager', true) as $eq) {
			if ($eq->getConfiguration('eqType') === 'manager') {
				continue;
			}
			$p = $eq->getCmd(null, 'power');
			$s = $eq->getCmd(null, 'status');
			$pi = $eq->getCmd(null, 'piloted_info');
			$pon = $eq->getCmd(null, 'pilot_on');
			$poff = $eq->getCmd(null, 'pilot_off');
			$consumers[] = array(
				'piloted' => ((int) $eq->getConfiguration('piloted', 1) === 1) ? 1 : 0,
				'piloted_id' => is_object($pi) ? $pi->getId() : 0,
				'pilot_on_id' => is_object($pon) ? $pon->getId() : 0,
				'pilot_off_id' => is_object($poff) ? $poff->getId() : 0,
				'eq' => $eq->getId(), 'name' => $eq->getName(), 'type' => $eq->getConfiguration('eqType'),
				'power_id' => is_object($p) ? $p->getId() : 0, 'power' => is_object($p) ? (float) $p->execCmd() : 0,
				'status_id' => is_object($s) ? $s->getId() : 0, 'status' => is_object($s) ? (string) $s->execCmd() : '',
			);
		}
		$consumersJson = json_encode($consumers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
		$replace['#consumers#'] = ($consumersJson === false) ? '[]' : $consumersJson;
		$html = template_replace($replace, getTemplate('core', $version, 'energyManager', 'energyManager'));
		return $this->postToHtml($_version, $html);
	}
}

class energyManagerCmd extends cmd {

	/** Les commandes info ne peuvent pas être supprimées depuis la page de configuration (elles sont recréées à l'enregistrement). */
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
					$eq->checkAndUpdateCmd('tesla_priority_info', 'tesla');
					if ((int) energyManager::cfg('simulation', 1) !== 1) { energyManager::syncTeslaPriority(); }
					break;
				case 'prio_battery':
					config::save('tesla_priority', 'battery', 'energyManager');
					$eq->checkAndUpdateCmd('tesla_priority_info', 'battery');
					if ((int) energyManager::cfg('simulation', 1) !== 1) { energyManager::syncTeslaPriority(); }
					break;
				case 'target_95':
					config::save('batt_target', 95, 'energyManager');
					$eq->checkAndUpdateCmd('batt_target_info', 95);
					break;
				case 'target_100':
					config::save('batt_target', 100, 'energyManager');
					$eq->checkAndUpdateCmd('batt_target_info', 100);
					break;
				case 'sim_on':
					$wasReal = ((int) energyManager::cfg('simulation', 1) !== 1);
					config::save('simulation', 1, 'energyManager');
					$eq->checkAndUpdateCmd('sim_info', 1);
					if ($wasReal) {
						// plus aucun ordre ne partira : on rend d'abord les limites réduites à leur valeur maximale
						energyManager::restoreLimits('passage en simulation : limite remise à sa valeur maximale');
					}
					break;
				case 'sim_off':
					config::save('simulation', 0, 'energyManager');
					$eq->checkAndUpdateCmd('sim_info', 0);
					break;
				case 'threshold_set':
					$v = isset($_options['slider']) ? (float) $_options['slider'] : (float) energyManager::cfg('battery_threshold', 80);
					$v = max(0, min(100, round($v)));
					config::save('battery_threshold', $v, 'energyManager');
					$eq->checkAndUpdateCmd('threshold_info', $v);
					if ((int) energyManager::cfg('simulation', 1) !== 1) { energyManager::syncTeslaThreshold(); }
					break;
				case 'reserve_set':
					$v = isset($_options['slider']) ? (float) $_options['slider'] : (float) energyManager::cfg('night_reserve', 50);
					$v = max(0, min(100, round($v)));
					config::save('night_reserve', $v, 'energyManager');
					config::save('reserve_auto', 0, 'energyManager'); // un réglage à la main passe en manuel
					$eq->checkAndUpdateCmd('reserve_info', $v);
					$eq->checkAndUpdateCmd('reserve_auto_info', 0);
					break;
				case 'reserve_auto_on':
					config::save('reserve_auto', 1, 'energyManager');
					$eq->checkAndUpdateCmd('reserve_auto_info', 1);
					$eq->checkAndUpdateCmd('reserve_info', energyManager::effectiveReserve());
					break;
				case 'reserve_auto_off':
					config::save('night_reserve', round(energyManager::effectiveReserve()), 'energyManager'); // on garde la valeur du moment
					config::save('reserve_auto', 0, 'energyManager');
					$eq->checkAndUpdateCmd('reserve_auto_info', 0);
					$eq->checkAndUpdateCmd('reserve_info', energyManager::effectiveReserve());
					break;
				case 'active_on':
					config::save('pilot_active', 1, 'energyManager'); // jamais la clé "active" : c'est l'état d'activation du plugin dans Jeedom
					$eq->checkAndUpdateCmd('active_info', 1);
					break;
				case 'active_off':
					config::save('pilot_active', 0, 'energyManager');
					$eq->checkAndUpdateCmd('active_info', 0);
					break;
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

		if ($id === 'pilot_on' || $id === 'pilot_off') {
			$eq->setConfiguration('piloted', ($id === 'pilot_on') ? 1 : 0);
			$eq->save(true);
			$eq->checkAndUpdateCmd('piloted_info', ($id === 'pilot_on') ? 1 : 0);
			if ($id === 'pilot_off') {
				energyManager::releaseEquipment($eq);
			} else {
				energyManager::engageEquipment($eq);
			}
			return;
		}
		if ($id === 'force_edf') {
			cache::set('energyManager::ecs_force::' . $eq->getId(), date('Ymd'));
			return;
		}
		if ($type === 'tesla') {
			// teslasmartcharge : set_mode attend select = Solaire | Stand-By | Non actif
			$map = array('mode_solar' => 'Solaire', 'mode_inactive' => 'Non actif');
			if (isset($map[$id])) {
				$target = energyManager::resolveCmd($eq->getConfiguration('cmd_set_mode'));
				if ($target === null) {
					throw new Exception(__('Commande set_mode de teslasmartcharge non configurée', __FILE__));
				}
				if ((int) energyManager::cfg('simulation', 1) === 1) {
					log::add('energyManager', 'info', '[SIMULATION] ' . $eq->getName() . ' → mode ' . $map[$id] . ' (non envoyé)');
					return;
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
