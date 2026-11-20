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

/**
 * Moteur de décision d'Energy Manager.
 *
 * Classe PURE : aucune dépendance à Jeedom, aucune entrée/sortie.
 * Elle reçoit une photo de l'installation (mesures, configuration, équipements,
 * mémoire du cycle précédent) et renvoie des décisions (actions, alertes, infos,
 * nouvelle mémoire). C'est ce qui permet le mode simulation et les tests.
 *
 * Conventions de signe :
 *   grid  > 0 : soutirage EDF      grid < 0 : injection vers EDF
 *   batt  > 0 : charge batterie    batt < 0 : décharge batterie
 */
class energyManagerEngine {

	const DEFAULTS = array(
		'soc_min'           => 10,    // % - plancher batterie
		'batt_target'       => 100,   // % - 95 ou 100 (95 = on garde de la place pour encaisser un surplus)
		'charge_max'        => 3680,  // W - limite de charge batterie du Victron
		'inv_max'           => 4000,  // W - limite onduleur du Victron
		'hoy_max'           => 7600,  // W - limite maximale des micro-onduleurs
		'hoy_step_up'       => 200,   // W - pas de remontée de la limite Hoymiles
		'hoy_min_delta'     => 50,    // W - écart minimal pour écrire la limite Hoymiles (flash)
		'hoy_write_interval'=> 60,    // s - délai mini entre 2 écritures Hoymiles (hors injection critique)
		'inj_critical'      => 500,   // W - injection considérée critique
		'inj_legal'         => 3000,  // W - maximum légal (jamais atteint si tout va bien)
		'inj_critical_hold' => 20,    // s - durée d'injection critique avant alerte
		'imp_off'           => 150,   // W - soutirage au-delà duquel on délestre
		'imp_hold'          => 30,    // s - durée de soutirage avant délestage
		'start_ratio'       => 0.7,   // part de la puissance théorique qui doit être disponible pour démarrer
		'settle'            => 30,    // s - attente après un démarrage avant le suivant
		'min_pv'            => 300,   // W - en dessous, on considère qu'il n'y a pas de soleil
		'tesla_margin'      => 300,   // W - marge laissée à teslasmartcharge en mode solaire
		'tesla_priority'    => 'tesla', // 'tesla' ou 'battery'
		'battery_threshold' => 80,    // % - en priorité Batterie : la batterie passe d'abord jusqu'à ce SOC, puis la Tesla (curseur du widget)
		'tesla_room_margin' => 500,   // W - la Tesla peut encore absorber si sa puissance est inférieure de plus que cette marge à son maximum
		'tesla_grace'       => 60,   // s - délai laissé à la Tesla (confirmation 60 s + API) avant d'agir sans elle
		'tesla_min_w'       => 460,   // W - plus petite charge de teslasmartcharge (2 A x 230 V) ; la plage va jusqu'à 32 A (~7 kW)
		'tesla_patience'    => 300,   // s - injection critique durable malgré une Tesla en solaire : on la considère bloquée (API, réveil...)
		'tesla_trigger_w'   => 500,   // W - puissance libérée pour DÉCLENCHER la charge de la Tesla (quelle que soit l'agressivité)
		'tesla_jump'        => 800,   // W - hausse brutale de la charge Tesla (réglage manuel) : la limite Hoymiles passe au maximum
		'inv_release_delay' => 30,    // s - la Tesla doit être à l'arrêt depuis ce délai avant de rendre l'onduleur à sa valeur maximale
		'hoy_band'          => 0,     // W - tolérance au-delà du budget d'injection de la Tesla avant de réduire (aucune : jamais plus que l'agressivité)
		'hoy_step_interval' => 30,    // s - délai mini entre deux paliers de libération de la limite Hoymiles
		'tesla_hold_max'    => 1500,  // W - au-delà de cette injection, on n'attend plus la Tesla
		'ecs_pv_ok'         => 500,   // W - (réservé)
		'night_reserve'     => 50,    // % - SOC à garder pour la nuit (jauge du widget)
		'ecs_deadline'      => 960,   // minute du jour (16 h) : le ballon doit avoir atteint son thermostat
		'ecs_absorb_temp'   => 50,    // °C - au-delà, le ballon n'absorbe plus (limite Hoymiles)
		'ecs_liters'        => 200,   // L - pour estimer l'énergie à chauffer
		'ecs_nominal'       => 60,    // °C - température visée pour cette estimation
		'evening'           => 1140,  // minute du jour (19 h) : le soir, quand la production passe sous le talon
		'talon'             => 400,   // W - consommation de base de la maison
		'batt_kwh'          => 16,    // kWh - capacité de la batterie
	);

	/**
	 * @param array $s photo de l'installation :
	 *   now (timestamp), minute (minute du jour 0-1439), grid, pv, soc, batt, house,
	 *   stale (liste de clés de mesures périmées), cfg (surcharges de DEFAULTS),
	 *   hoy_limit (limite actuelle), charge_limit (limite actuelle),
	 *   hc (bool, heure creuse en cours), equips (liste), mem (mémoire précédente)
	 * @return array actions, alerts, info, mem
	 */
	public static function decide(array $s) {
		$cfg = array_merge(self::DEFAULTS, isset($s['cfg']) ? $s['cfg'] : array());
		$mem = array_merge(array(
			'inj_since' => 0, 'imp_since' => 0, 'inj_any_since' => 0, 'last_start' => 0, 'last_hoy_write' => 0, 'hoy_hidden' => 0, 'tesla_prev_w' => 0, 'tesla_off_since' => 0,
		), isset($s['mem']) ? $s['mem'] : array());
		$out = array('actions' => array(), 'alerts' => array(), 'info' => array(), 'mem' => $mem);
		$now = (int) $s['now'];

		/* ---------- 1. Cohérence des données : sinon on ne pilote rien ---------- */
		$stale = isset($s['stale']) ? $s['stale'] : array();
		if (count($stale) > 0) {
			$out['alerts'][] = array('key' => 'stale', 'level' => 'critical',
				'message' => 'Donnée obsolète ou absente : ' . implode(', ', $stale) . '. Aucun pilotage.');
			$out['info']['decision'] = 'Sécurité : données obsolètes, aucun pilotage';
			return $out;
		}
		foreach (array('grid', 'pv', 'soc') as $k) {
			if (!isset($s[$k]) || !is_numeric($s[$k])) {
				$out['alerts'][] = array('key' => 'incoherent', 'level' => 'critical',
					'message' => 'Donnée incohérente (' . $k . ' absente). Aucun pilotage.');
				$out['info']['decision'] = 'Sécurité : donnée absente (' . $k . ')';
				return $out;
			}
		}
		if ($s['soc'] < 0 || $s['soc'] > 100 || $s['pv'] < -50 || $s['pv'] > $cfg['hoy_max'] * 1.3) {
			$out['alerts'][] = array('key' => 'incoherent', 'level' => 'critical',
				'message' => 'Donnée incohérente : SOC=' . $s['soc'] . '% PV=' . $s['pv'] . 'W. Aucun pilotage.');
			$out['info']['decision'] = 'Sécurité : données incohérentes';
			return $out;
		}

		$grid = (float) $s['grid'];
		$pv = max(0, (float) $s['pv']);
		$soc = (float) $s['soc'];
		$injection = max(0, -$grid);
		$import = max(0, $grid);
		$daytime = $pv >= $cfg['min_pv'];
		$hoyLimit = isset($s['hoy_limit']) ? (float) $s['hoy_limit'] : (float) $cfg['hoy_max'];
		$chargeLimit = isset($s['charge_limit']) ? (float) $s['charge_limit'] : (float) $cfg['charge_max'];

		// durées d'état
		$mem['inj_since'] = ($injection >= $cfg['inj_critical']) ? ($mem['inj_since'] ?: $now) : 0;
		$mem['imp_since'] = ($import >= $cfg['imp_off']) ? ($mem['imp_since'] ?: $now) : 0;
		$mem['inj_any_since'] = ($injection >= 100) ? ($mem['inj_any_since'] ?: $now) : 0;

		$equips = isset($s['equips']) ? $s['equips'] : array();

		/* ---------- 2. Tesla en mode solaire : teslasmartcharge régule seul ---------- */
		$teslaSolar = false;
		$teslaRoom = false;
		$teslaPower = 0.0;
		$teslaEq = null;
		foreach ($equips as $e) {
			if ($e['type'] === 'tesla' && $e['mode'] === 'auto' && !empty($e['active'])) {
				$teslaSolar = true;
				$teslaRoom = ((float) $e['power_real'] < (float) $e['power_theo'] - $cfg['tesla_room_margin']);
				$teslaPower = (float) $e['power_real'];
				$teslaEq = $e;
			}
		}
		// Priorité effective : en priorité Batterie, la batterie passe d'abord (charge à pleine puissance) jusqu'au seuil de SOC choisi ;
		// au-delà, la Tesla passe d'abord comme en priorité Tesla.
		$prioEff = ($cfg['tesla_priority'] === 'battery' && $soc >= $cfg['battery_threshold']) ? 'tesla' : $cfg['tesla_priority'];
		// Marge de teslasmartcharge (-300 à +300 W) : négative, elle autorise un soutirage EDF de |marge| W. On l'accepte (pas de réaction).
		$tolerance = ($teslaSolar && $teslaEq !== null && isset($teslaEq['margin']) && is_numeric($teslaEq['margin'])) ? max(0, -(float) $teslaEq['margin']) : 0;
		// Priorité Tesla : tant qu'elle peut absorber et qu'elle a eu le temps de réagir, les autres consommateurs
		// attendent (teslasmartcharge confirme chaque changement pendant 60 s). Une grosse injection n'attend pas.
		$teslaGraceOver = $mem['inj_any_since'] > 0 && ($now - $mem['inj_any_since']) >= $cfg['tesla_grace'];
		$teslaHolds = $teslaSolar && $teslaRoom && $prioEff === 'tesla'
			&& !$teslaGraceOver && $injection < $cfg['tesla_hold_max'];

		// Tesla branchée : teslasmartcharge reste en Stand-By tant qu'on ne le met pas en Solaire. On le fait UNE fois par branchement
		// (plug_handled mémorisé côté plugin) ; jamais si le mode est "Non actif" ou "Planifié" choisi par l'utilisateur.
		foreach ($equips as $e) {
			if ($e['type'] === 'tesla' && $e['mode'] === 'auto' && !empty($e['plugged']) && empty($e['plug_handled'])
				&& isset($e['tesla_mode']) && $e['tesla_mode'] === 'Stand-By') {
				$out['actions'][] = array('type' => 'tesla_mode', 'id' => $e['id'], 'value' => 'Solaire',
					'reason' => 'voiture branchée : teslasmartcharge passe en mode Solaire');
			}
		}

		// Surplus que le BALLON peut réclamer.
		// Il est prioritaire sur la batterie et sur la Tesla, MAIS seulement si la batterie gardera de quoi tenir la nuit :
		//  - avec une prévision solaire (kWh restants) : SOC projeté au soir = SOC + (prévision - talon jusqu'au soir - énergie du ballon) / capacité
		//  - sans prévision : le SOC actuel (la batterie ne descend pas sous son SOC actuel en journée)
		// Sinon, le ballon ne prend que l'injection réelle (batterie d'abord) ou l'heure creuse EDF.
		$battCharge = (isset($s['batt']) && is_numeric($s['batt'])) ? max(0, (float) $s['batt']) : 0;
		$battNow = (isset($s['batt']) && is_numeric($s['batt'])) ? (float) $s['batt'] : 0;
		$teslaDraw = 0;
		foreach ($equips as $e) {
			if ($e['type'] === 'tesla' && $e['mode'] === 'auto' && !empty($e['active'])) {
				$teslaDraw += max(0, (float) $e['power_real']);
			}
		}
		$potential = $injection + $battCharge + $teslaDraw;
		$minuteNow = isset($s['minute']) ? (int) $s['minute'] : 0;
		$forecast = (isset($s['forecast_kwh']) && is_numeric($s['forecast_kwh'])) ? (float) $s['forecast_kwh'] : null;
		$projected = $soc;
		if ($forecast !== null) {
			$hoursLeft = max(0, ((float) $cfg['evening'] - $minuteNow) / 60);
			$ecsKwh = 0;
			foreach ($equips as $e) {
				if ($e['type'] === 'ecs' && $e['mode'] === 'auto') {
					$wt = (isset($e['water_temp']) && $e['water_temp'] !== null) ? (float) $e['water_temp'] : 20.0;
					$ecsKwh += max(0, $cfg['ecs_liters'] * 1.163 * ($cfg['ecs_nominal'] - $wt) / 1000);
				}
			}
			$projected = $soc + (($forecast - $cfg['talon'] * $hoursLeft / 1000 - $ecsKwh) / $cfg['batt_kwh']) * 100;
		}
		$projected = min(100, $projected);
		$ecsMayTake = ($projected >= $cfg['night_reserve']);
		$ecsAvail = $ecsMayTake ? $potential : $injection;
		$s['_potential'] = $ecsAvail;
		$waiting = array();

		/* ---------- 3. Limite de charge batterie ---------- */
		$newCharge = $cfg['charge_max'];
		$reasonCharge = 'charge libre';
		// Priorité Tesla : la batterie ne se charge pas du tout, la Tesla prend toute la puissance disponible. Cela tant que la Tesla est en mode
		// solaire (branchée) et qu'elle a de la place : si elle est pleine ou arrêtée par teslasmartcharge, la batterie reprend la charge.
		// Priorité Batterie (sous le seuil de SOC) : la batterie se charge à pleine puissance, la Tesla ne prend que le reste.
		if ($teslaSolar && $teslaRoom && $prioEff === 'tesla') {
			$newCharge = 0;
			$reasonCharge = 'priorité Tesla : la batterie ne charge pas, la Tesla prend toute la puissance';
		} elseif ($soc >= $cfg['batt_target']) {
			// batterie "pleine" selon le sélecteur (95 % ou 100 %)
			$newCharge = 0;
			$reasonCharge = 'batterie à ' . $cfg['batt_target'] . ' %, charge stoppée';
			// tampon : si un surplus critique arrive et qu'il reste de la place, on l'utilise le temps que les consommateurs réagissent
			if ($injection >= $cfg['inj_critical'] && $soc < 100) {
				$newCharge = $cfg['charge_max'];
				$reasonCharge = 'tampon batterie utilisé pour absorber un surplus critique';
			}
		}
		if (abs($newCharge - $chargeLimit) >= 5) {
			$out['actions'][] = array('type' => 'charge_limit', 'value' => $newCharge, 'reason' => $reasonCharge);
		}

		/* ---------- 4. Consommateurs ---------- */
		$started = false;
		$startedPower = 0.0;     // puissance des consommateurs solaires démarrés dans ce cycle : elle est libérée sur la limite Hoymiles
		$wantsPending = false;   // un consommateur voudrait démarrer mais attend
		$cands = array();
		foreach ($equips as $e) {
			if ($e['type'] === 'tesla' || $e['type'] === 'clim') {
				continue; // piloté par son propre plugin / mis en stand-by
			}
			$w = self::wants($e, $cfg, $s, $daytime, $pv);
			$e['_want'] = $w['want'];
			$e['_source'] = $w['source'];
			$e['_why'] = $w['why'];
			$e['_sort'] = ($e['type'] === 'ecs' ? 0 : (int) $e['priority']) * 100000 + (int) $e['id'];
			$cands[] = $e;
		}
		usort($cands, function ($a, $b) { return $a['_sort'] - $b['_sort']; });

		// 4a. extinctions simples : le besoin a disparu (résistances : pas d'hystérésis)
		foreach ($cands as $e) {
			// option ballon : ne pas couper quand le thermostat a atteint sa température
			$keepOn = ($e['type'] === 'ecs' && !empty($e['done_today']) && isset($e['cut_on_zero']) && !$e['cut_on_zero']);
			if ($e['is_on'] && !$e['_want'] && $e['mode'] !== 'on' && !$keepOn) {
				$out['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'off', 'reason' => $e['_why']);
				$e['is_on'] = false;
			}
		}

		// 4b. délestage : soutirage durable, ou plus de soleil, on coupe le moins prioritaire alimenté en solaire
		$shedding = $mem['imp_since'] > 0 && ($now - $mem['imp_since']) >= $cfg['imp_hold'] && !$teslaSolar;
		if ($shedding || !$daytime) {
			$rev = array_reverse($cands);
			foreach ($rev as $e) {
				if ($e['is_on'] && $e['mode'] === 'auto' && $e['_source'] === 'solar'
					&& !self::alreadyOff($out['actions'], $e['id'])) {
					$out['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'off',
						'reason' => $daytime ? 'soutirage EDF de ' . round($import) . ' W : délestage' : 'plus de production solaire');
					if ($daytime) {
						break; // un seul par cycle quand c'est du délestage
					}
				}
			}
		}

		// 4b'. réserve de nuit : le ballon alimenté en solaire ne doit pas vider la batterie
		if (!$ecsMayTake && $battNow <= -100) {
			foreach ($cands as $e) {
				if ($e['type'] === 'ecs' && $e['is_on'] && $e['mode'] === 'auto' && $e['_source'] === 'solar'
					&& !self::alreadyOff($out['actions'], $e['id'])) {
					$out['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'off',
						'reason' => 'réserve de nuit : le ballon ne doit pas décharger la batterie (SOC projeté ' . round($projected) . ' % < ' . round($cfg['night_reserve']) . ' %)');
				}
			}
		}

		// 4c. démarrage : forcés et besoins EDF autorisés d'abord, puis surplus solaire
		foreach ($cands as $e) {
			if ($e['is_on'] || !$e['_want'] || self::alreadyOff($out['actions'], $e['id'])) {
				continue;
			}
			if ($e['mode'] === 'on' || $e['_source'] === 'edf') {
				$out['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'on', 'reason' => $e['_why']);
				continue;
			}
			// solaire : un seul démarrage par cycle, et seulement si le surplus le justifie
			// le ballon est prioritaire : il n'attend ni la Tesla ni la batterie (sous réserve de la réserve de nuit)
			$isEcs = ($e['type'] === 'ecs');
			if ($started || ($teslaHolds && !$isEcs)) {
				$wantsPending = true;
				$waiting[] = $e['name'] . ' attend ' . ($started ? 'le prochain cycle (un démarrage à la fois)' : 'que la Tesla monte en puissance (priorité Tesla)');
				continue;
			}
			// surplus visible + surplus déjà écrêté par la limite Hoymiles : on allume d'abord le consommateur, puis on libère sa puissance
			$avail = ($isEcs ? $ecsAvail : $injection) + (float) $mem['hoy_hidden'];
			$need = $e['power_theo'] * $cfg['start_ratio'] + (($teslaSolar && !$isEcs) ? $cfg['tesla_margin'] : 0);
			$settled = ($now - $mem['last_start']) >= $cfg['settle'];
			if ($avail >= $need && $settled) {
				$out['actions'][] = array('type' => 'equip', 'id' => $e['id'], 'cmd' => 'on',
					'reason' => 'surplus ' . round($avail) . ' W ≥ ' . round($need) . ' W' . ($isEcs && $ecsMayTake ? ' (prioritaire sur la batterie)' : '') . ' : ' . $e['_why']);
				$mem['last_start'] = $now;
				$started = true;
				$startedPower += (float) $e['power_theo'];
			} else {
				$wantsPending = true;
				$why = $e['name'] . ' attend : surplus ' . round($avail) . ' W < ' . round($need) . ' W' . ($settled ? '' : ' (stabilisation)');
				if ($isEcs && !$ecsMayTake) {
					$why .= ' — réserve de nuit : SOC projeté ' . round($projected) . ' % < ' . round($cfg['night_reserve']) . ' % (le ballon n\'utilise que l\'injection ou l\'heure creuse EDF)';
				}
				$waiting[] = $why;
			}
		}

		/* ---------- 5. Limite Hoymiles : jamais d'injection au-delà de ce que la Tesla peut absorber ---------- */
		// Règle : injection autorisée = agressivité de la Tesla (500 / 1000 / 2000 W) tant qu'elle est active et peut absorber ; sinon AUCUNE.
		// Sans Tesla active, on allume un consommateur, puis on libère exactement sa puissance, et ainsi de suite.
		$newHoy = $hoyLimit;
		$reasonHoy = '';
		$critical = $injection >= $cfg['inj_critical'];
		// Tesla bloquée : injection critique qui dure malgré une Tesla en solaire (API, réveil...) => elle ne compte plus comme consommateur
		$teslaStuck = ($mem['inj_since'] > 0 && ($now - $mem['inj_since']) >= $cfg['tesla_patience']);
		$teslaCan = $teslaSolar && $teslaRoom && !$teslaStuck;
		$battLimit = min($newCharge, $cfg['charge_max']);
		$battRoomW = ($battLimit >= 100 && $soc < 98) ? max(0, $battLimit - $battCharge) : 0;   // puissance que la batterie pourrait encore absorber
		$forceWrite = false;
		$stepRaise = false;

		// Budget d'injection de la Tesla : pas encore en charge, 500 W juste de quoi la déclencher ; en charge, l'agressivité de l'équipement.
		$budget = null;
		if ($teslaCan) {
			$aggr = ($teslaEq !== null && isset($teslaEq['aggr_w']) && $teslaEq['aggr_w'] > 0) ? (float) $teslaEq['aggr_w'] : 1000.0;
			$budget = ($teslaPower >= $cfg['tesla_min_w']) ? $aggr : min((float) $cfg['tesla_trigger_w'], $aggr);
		}
		// Réglage manuel de la charge Tesla à la hausse : la limite passe au maximum pour compenser
		$teslaJump = ($teslaPower - (float) $mem['tesla_prev_w']) >= $cfg['tesla_jump'];
		$mem['tesla_prev_w'] = $teslaPower;

		$allowed = ($budget !== null) ? (float) $budget : 0.0;
		$band = ($budget !== null) ? (float) $cfg['hoy_band'] : (float) $cfg['hoy_min_delta'];
		// injection qui restera quand le consommateur démarré dans ce cycle aura pris sa puissance
		$injNet = max(0.0, $injection - $startedPower);

		if ($injNet > $allowed + $band) {
			// trop d'injection : on réduit juste ce qu'il faut pour revenir au budget (zéro sans Tesla active)
			$newHoy = max(0, min($hoyLimit, $pv) - $injNet + $allowed);
			if ($budget !== null) {
				$reasonHoy = 'injection ' . round($injNet) . ' W > budget ' . round($budget) . ' W (Tesla ' . ($teslaPower >= $cfg['tesla_min_w'] ? 'en charge' : 'à déclencher') . ') : limitation à ' . round($newHoy) . ' W';
			} else {
				$reasonHoy = 'injection ' . round($injNet) . ' W : aucune charge ne peut l\'absorber, limitation à ' . round($newHoy) . ' W';
			}
			$mem['hoy_hidden'] = max((float) $mem['hoy_hidden'], $injNet - $allowed);
		} elseif ($hoyLimit < $cfg['hoy_max']) {
			if (!$daytime) {
				$newHoy = $cfg['hoy_max'];
				$reasonHoy = 'plus de soleil : limite remise au maximum';
			} elseif ($import > $tolerance || $teslaJump) {
				$newHoy = $cfg['hoy_max'];
				$reasonHoy = ($teslaJump ? 'charge Tesla augmentée' : 'soutirage EDF de ' . round($import) . ' W') . ' : limite remise au maximum';
				$forceWrite = true;
			} elseif ($startedPower > 0) {
				// un consommateur vient d'être allumé : on libère exactement sa puissance
				$newHoy = min((float) $cfg['hoy_max'], $hoyLimit + $startedPower);
				$reasonHoy = 'consommateur démarré : +' . round($startedPower) . ' W libérés';
				$mem['hoy_hidden'] = max(0.0, (float) $mem['hoy_hidden'] - $startedPower);
				$forceWrite = true;
			} elseif ($budget !== null) {
				if ($pv >= 0.95 * $hoyLimit && $injection < max(100, 0.25 * $budget)) {
					// la production est bridée et la Tesla a consommé ce qui était libéré : palier suivant
					$newHoy = min($cfg['hoy_max'], $hoyLimit + $budget);
					$reasonHoy = 'Tesla consomme la puissance libérée : palier de +' . round($budget) . ' W';
					$stepRaise = true;
				}
			} elseif ($battRoomW >= 200 && $pv >= 0.95 * $hoyLimit && $injection <= $cfg['hoy_min_delta']) {
				// la batterie peut absorber davantage : on libère ce qu'elle peut prendre
				$newHoy = min((float) $cfg['hoy_max'], $hoyLimit + $battRoomW);
				$reasonHoy = 'la batterie peut absorber +' . round($battRoomW) . ' W : on les libère';
				$stepRaise = true;
			}
		}
		if ($newHoy >= $cfg['hoy_max']) {
			$mem['hoy_hidden'] = 0;
		}
		$newHoy = max(0, min($cfg['hoy_max'], $newHoy));
		$delta = abs($newHoy - $hoyLimit);
		$intervalOk = ($now - $mem['last_hoy_write']) >= $cfg['hoy_write_interval'];
		$stepOk = ($now - $mem['last_hoy_write']) >= $cfg['hoy_step_interval'];
		$isReduction = $newHoy < $hoyLimit;
		if ($reasonHoy !== '' && $delta >= $cfg['hoy_min_delta'] && ($intervalOk || $forceWrite || ($critical && $isReduction) || ($stepRaise && $stepOk))) {
			$out['actions'][] = array('type' => 'hoymiles', 'value' => round($newHoy), 'reason' => $reasonHoy);
			$mem['last_hoy_write'] = $now;
		}

		/* ---------- 5b. Onduleur Victron : pas de décharge de la batterie maison pendant que la Tesla charge ---------- */
		// teslasmartcharge ne doit vivre que du soleil (+ sa marge EDF). Pendant la charge, la limite onduleur passe à 0 W ;
		// elle revient au maximum quand la Tesla ne charge plus (arrêtée, débranchée...), après un court délai pour éviter les va-et-vient.
		$invCurrent = (isset($s['inv_limit']) && is_numeric($s['inv_limit'])) ? (float) $s['inv_limit'] : (float) $cfg['inv_max'];
		$teslaChargingAny = false;
		foreach ($equips as $e) {
			if ($e['type'] === 'tesla' && (float) $e['power_real'] >= $cfg['tesla_min_w']) {
				$teslaChargingAny = true;
			}
		}
		$mem['tesla_off_since'] = $teslaChargingAny ? 0 : ($mem['tesla_off_since'] ?: $now);
		$wantInv = (float) $cfg['inv_max'];
		$reasonInv = 'la Tesla ne charge plus : onduleur remis à sa valeur maximale';
		if ($teslaChargingAny) {
			$wantInv = 0.0;
			$reasonInv = 'Tesla en charge : la batterie ne doit pas décharger (onduleur à 0 W)';
		} elseif (($now - $mem['tesla_off_since']) < $cfg['inv_release_delay']) {
			$wantInv = $invCurrent; // arrêt trop récent : on attend
		}
		if (abs($wantInv - $invCurrent) >= 5) {
			$out['actions'][] = array('type' => 'inv_limit', 'value' => round($wantInv), 'reason' => $reasonInv);
		}

		/* ---------- 6. Alertes ---------- */
		if ($mem['inj_since'] > 0 && ($now - $mem['inj_since']) >= $cfg['inj_critical_hold']
			&& !($budget !== null && $injection <= $budget + $cfg['hoy_band'])) {
			$out['alerts'][] = array('key' => 'injection', 'level' => 'critical',
				'message' => 'Injection critique : ' . round($injection) . ' W depuis ' . ($now - $mem['inj_since']) . ' s.');
		}
		if ($injection >= $cfg['inj_legal']) {
			$out['alerts'][] = array('key' => 'injection_legal', 'level' => 'critical',
				'message' => 'Injection ' . round($injection) . ' W : maximum légal (' . $cfg['inj_legal'] . ' W) atteint !');
		}

		// échéance du ballon : température nominale non atteinte à l'heure limite => OFF (voir wants) + alerte Discord, une fois par jour
		foreach ($equips as $e) {
			if ($e['type'] !== 'ecs' || $e['mode'] !== 'auto' || !empty($e['force_edf'])) {
				continue;
			}
			$wt = (isset($e['water_temp']) && $e['water_temp'] !== null) ? $e['water_temp'] : null;
			$coldNow = ($wt === null) || ($wt < (isset($e['min_temp']) ? $e['min_temp'] : 50));
			if ($coldNow && $minuteNow >= $cfg['ecs_deadline'] && $minuteNow < $cfg['ecs_deadline'] + 120) {
				$out['alerts'][] = array('key' => 'ecs_deadline_' . $e['id'], 'level' => 'critical', 'once' => true,
					'message' => 'Ballon « ' . $e['name'] . ' » : température nominale non atteinte à ' . self::hm($cfg['ecs_deadline'])
						. ' (eau à ' . ($wt === null ? '?' : round($wt, 1)) . ' °C). Il est mis sur OFF.');
			}
		}

		/* ---------- 7. Informations de synthèse ---------- */
		$out['info'] = array(
			'injection' => round($injection),
			'import'    => round($import),
			'daytime'   => $daytime ? 1 : 0,
			'tesla_solar' => $teslaSolar ? 1 : 0,
			'tesla_holds' => $teslaHolds ? 1 : 0,
			'ecs_may_take' => $ecsMayTake ? 1 : 0,
			'hoy_budget' => ($budget === null) ? 0 : round($budget),
			'night_projected' => round($projected),
			'wants_pending' => $wantsPending ? 1 : 0,
			'decision'  => count($out['actions']) ? self::summarize($out['actions']) : 'Aucune action (injection ' . round($injection) . ' W, soutirage ' . round($import) . ' W, SOC ' . round($soc) . ' %)' . (count($waiting) ? ' — ' . implode(' ; ', $waiting) : ''),
			'waiting'   => $waiting,
		);
		$out['mem'] = $mem;
		return $out;
	}

	/**
	 * Un équipement a-t-il besoin d'énergie, et de quelle source ?
	 * @return array want (bool), source ('solar'|'edf'|'forced'), why (texte)
	 */
	public static function wants(array $e, array $cfg, array $s, $daytime, $pv) {
		$no = function ($why) { return array('want' => false, 'source' => 'solar', 'why' => $why); };
		if ($e['mode'] === 'off') {
			return $no('équipement inactif (forcé OFF)');
		}
		if ($e['mode'] === 'on') {
			return array('want' => true, 'source' => 'forced', 'why' => 'forcé ON par l\'utilisateur');
		}

		if ($e['type'] === 'ecs') {
			$water = isset($e['water_temp']) ? $e['water_temp'] : null;
			$minT = isset($e['min_temp']) ? $e['min_temp'] : 50;
			$cold = ($water === null) || ($water < $minT);
			// "terminé" (thermostat atteint) ne vaut que tant que l'eau reste chaude : si elle redescend sous le minimum, on la rechauffe
			if (!empty($e['done_today']) && !$cold) {
				return $no('ballon chaud pour la journée (thermostat atteint)');
			}
			// besoin EDF validé par l'utilisateur (réponse Discord) ou chauffe en HC de l'après-midi
			if (!empty($e['force_edf'])) {
				return array('want' => true, 'source' => 'edf', 'why' => 'complément EDF validé par l\'utilisateur');
			}
			// échéance : température nominale non atteinte à l'heure limite => on arrête (et decide() alerte)
			if ($cold && isset($s['minute']) && (int) $s['minute'] >= $cfg['ecs_deadline']) {
				return $no('ballon : température nominale non atteinte à ' . self::hm($cfg['ecs_deadline']) . ', passage sur OFF');
			}
			// surplus solaire que le ballon peut réclamer (batterie et Tesla comprises si la réserve de nuit le permet), calculé par decide()
			$pot = isset($s['_potential']) ? (float) $s['_potential'] : 0.0;
			$solarEnough = $daytime && $pot >= $e['power_theo'] * $cfg['start_ratio'];
			$running = !empty($e['is_on']);
			// HC d'après-midi : si le soleil ne suffit pas (ou prévision médiocre), EDF chauffe le ballon.
			// Une fois lancé, on laisse chauffer jusqu'à ce que son thermostat coupe (la consommation retombe à zéro => "terminé").
			if (!empty($s['hc_afternoon']) && ($cold || $running) && (!$solarEnough || !empty($e['forecast_poor']))) {
				return array('want' => true, 'source' => 'edf', 'why' => 'heure creuse de l\'après-midi, soleil insuffisant, eau à ' . ($water === null ? '?' : $water) . ' °C');
			}
			if ($daytime) {
				return array('want' => true, 'source' => 'solar', 'why' => 'ballon : puits d\'énergie solaire');
			}
			return $no('ballon : pas de soleil ni d\'heure creuse d\'après-midi');
		}

		// consommateur simple (radiateur) : chauffer seulement en journée, avec le soleil
		if (!$daytime) {
			return $no('pas de soleil');
		}
		if (empty($e['season_heat'])) {
			return $no('saison : pas de chauffage');
		}
		if (!empty($e['window_open'])) {
			return $no('fenêtre ouverte');
		}
		if (!isset($e['temp']) || $e['temp'] === null) {
			return $no('température de pièce inconnue');
		}
		$limit = $e['target'] + (isset($e['boost']) ? $e['boost'] : 0);
		if ($e['temp'] < $limit) {
			return array('want' => true, 'source' => 'solar',
				'why' => 'pièce à ' . $e['temp'] . ' °C < ' . $limit . ' °C (consigne ' . $e['target'] . ' + boost solaire)');
		}
		return $no('pièce à ' . $e['temp'] . ' °C ≥ ' . $limit . ' °C');
	}

	private static function hm($_minute) {
		return sprintf('%02dh%02d', floor($_minute / 60), $_minute % 60);
	}

	private static function alreadyOff(array $actions, $id) {
		foreach ($actions as $a) {
			if ($a['type'] === 'equip' && $a['id'] == $id && $a['cmd'] === 'off') {
				return true;
			}
		}
		return false;
	}

	private static function summarize(array $actions) {
		$t = array();
		foreach ($actions as $a) {
			$t[] = $a['reason'];
		}
		return implode(' | ', $t);
	}

	/** Détecte un créneau HC/HP. $slots : liste de array(debutMin, finMin, 'HC'|'HP'). */
	public static function slotAt(array $slots, $minute) {
		foreach ($slots as $sl) {
			if ($sl[0] <= $sl[1]) {
				if ($minute >= $sl[0] && $minute < $sl[1]) {
					return $sl[2];
				}
			} elseif ($minute >= $sl[0] || $minute < $sl[1]) { // créneau à cheval sur minuit
				return $sl[2];
			}
		}
		return 'HP';
	}

	/**
	 * Lit les plages d'heures creuses : texte "00:50-06:50;14:20-16:20".
	 * (pas de JSON : Jeedom décode automatiquement les valeurs de configuration qui en ont l'allure)
	 * @return array liste de array(debutMin, finMin, 'HC')
	 */
	public static function parseSlots($text) {
		$slots = array();
		if (!is_string($text)) {
			return $slots;
		}
		if (preg_match_all('/(\d{1,2})\s*[:h]\s*(\d{2})\s*-\s*(\d{1,2})\s*[:h]\s*(\d{2})/', $text, $mm, PREG_SET_ORDER)) {
			foreach ($mm as $m) {
				$a = $m[1] * 60 + $m[2];
				$b = $m[3] * 60 + $m[4];
				if ($a != $b) {
					$slots[] = array($a, $b, 'HC');
				}
			}
		}
		return $slots;
	}

	/**
	 * Besoin de la nuit (kWh) d'après l'historique des dernières nuits : percentile 75 (on préfère voir un peu large),
	 * ou la valeur par défaut tant qu'il y a moins de $minSamples nuits mesurées.
	 */
	public static function nightNeed(array $history, $default, $minSamples = 3) {
		$h = array();
		foreach ($history as $v) {
			if (is_numeric($v) && (float) $v > 0) {
				$h[] = (float) $v;
			}
		}
		if (count($h) < $minSamples) {
			return (float) $default;
		}
		sort($h);
		$idx = (int) ceil(0.75 * count($h)) - 1;
		return round($h[max(0, min(count($h) - 1, $idx))], 2);
	}

	/**
	 * SOC (%) à avoir au soir pour tenir la nuit : SOC mini + besoin de la nuit (avec marge) / capacité.
	 * @param array $cfg soc_min, batt_kwh, night_margin (%)
	 */
	public static function reserveFromNeed($needKwh, array $cfg) {
		$socMin = isset($cfg['soc_min']) ? (float) $cfg['soc_min'] : 10;
		$cap = (isset($cfg['batt_kwh']) && $cfg['batt_kwh'] > 0) ? (float) $cfg['batt_kwh'] : 16;
		$margin = isset($cfg['night_margin']) ? (float) $cfg['night_margin'] : 15;
		$r = $socMin + ((float) $needKwh * (1 + $margin / 100) / $cap) * 100;
		return round(max($socMin + 10, min(95, $r)));
	}
}
