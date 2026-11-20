<?php
/**
 * Tests du moteur de décision (sans Jeedom).
 * Lancer :  php tests/engine_test.php
 */
require_once __DIR__ . '/../core/class/energyManagerEngine.class.php';

$fail = 0;
function check($name, $cond) {
	global $fail;
	echo ($cond ? '[ OK ] ' : '[FAIL] ') . $name . PHP_EOL;
	if (!$cond) {
		$fail++;
	}
}
function find_action($res, $type, $extra = array()) {
	foreach ($res['actions'] as $a) {
		if ($a['type'] !== $type) {
			continue;
		}
		$ok = true;
		foreach ($extra as $k => $v) {
			if (!isset($a[$k]) || $a[$k] != $v) {
				$ok = false;
			}
		}
		if ($ok) {
			return $a;
		}
	}
	return null;
}
function base($over = array()) {
	return array_merge(array(
		'now' => 1000000, 'minute' => 12 * 60, 'grid' => 0, 'pv' => 4000, 'soc' => 80, 'batt' => 1500, 'house' => 600,
		'stale' => array(), 'cfg' => array(), 'hoy_limit' => 7600, 'charge_limit' => 3680, 'hc_afternoon' => false,
		'equips' => array(), 'mem' => array(),
	), $over);
}
function rad($id, $over = array()) {
	return array_merge(array(
		'id' => $id, 'type' => 'simple', 'name' => 'Radiateur ' . $id, 'mode' => 'auto', 'is_on' => false,
		'power_real' => 0, 'power_theo' => 1000, 'priority' => 2, 'temp' => 18, 'target' => 20, 'boost' => 2,
		'window_open' => false, 'season_heat' => true,
	), $over);
}
function ecs($over = array()) {
	return array_merge(array(
		'id' => 50, 'type' => 'ecs', 'name' => 'Ballon', 'mode' => 'auto', 'is_on' => false,
		'power_real' => 0, 'power_theo' => 2300, 'priority' => 1, 'water_temp' => 40, 'min_temp' => 52, 'done_today' => false,
	), $over);
}

// 1. Données périmées : aucune action, alerte critique
$r = energyManagerEngine::decide(base(array('stale' => array('grid'))));
check('donnée périmée : aucune action', count($r['actions']) === 0);
check('donnée périmée : alerte', $r['alerts'][0]['key'] === 'stale');

// 2. SOC incohérent
$r = energyManagerEngine::decide(base(array('soc' => 140)));
check('SOC incohérent : aucune action', count($r['actions']) === 0 && $r['alerts'][0]['key'] === 'incoherent');

// 3. Surplus suffisant : un radiateur démarre
$r = energyManagerEngine::decide(base(array('grid' => -900, 'equips' => array(rad(1)))));
check('surplus 900 W : radiateur ON', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

// 4. Surplus insuffisant : rien ne démarre
$r = energyManagerEngine::decide(base(array('grid' => -300, 'equips' => array(rad(1)))));
check('surplus 300 W : pas de démarrage', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 5. Un seul démarrage par cycle, par priorité
$r = energyManagerEngine::decide(base(array('grid' => -2500, 'equips' => array(rad(1, array('priority' => 3)), rad(2, array('priority' => 1))))));
check('un seul démarrage par cycle', count(array_filter($r['actions'], function ($a) { return $a['type'] === 'equip'; })) === 1);
check('la priorité 1 passe avant la 3', find_action($r, 'equip', array('id' => 2, 'cmd' => 'on')) !== null);

// 6. Pièce assez chaude : pas de chauffe
$r = energyManagerEngine::decide(base(array('grid' => -2500, 'equips' => array(rad(1, array('temp' => 23))))));
check('pièce à 23 °C ≥ 22 : pas de chauffe', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 7. Fenêtre ouverte
$r = energyManagerEngine::decide(base(array('grid' => -2500, 'equips' => array(rad(1, array('window_open' => true))))));
check('fenêtre ouverte : pas de chauffe', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 8. Forcé OFF
$r = energyManagerEngine::decide(base(array('grid' => -2500, 'equips' => array(rad(1, array('mode' => 'off'))))));
check('forcé OFF respecté', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 9. Forcé ON démarre sans surplus
$r = energyManagerEngine::decide(base(array('grid' => 0, 'equips' => array(rad(1, array('mode' => 'on'))))));
check('forcé ON démarre sans surplus', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

// 10. Délestage après soutirage durable
$mem = array('imp_since' => 1000000 - 60);
$r = energyManagerEngine::decide(base(array('grid' => 400, 'mem' => $mem, 'equips' => array(rad(1, array('is_on' => true, 'priority' => 1)), rad(2, array('is_on' => true, 'priority' => 3))))));
check('soutirage durable : le moins prioritaire est coupé', find_action($r, 'equip', array('id' => 2, 'cmd' => 'off')) !== null);
check('soutirage durable : un seul délestage', find_action($r, 'equip', array('id' => 1, 'cmd' => 'off')) === null);

// 11. Nuit : les radiateurs gérés sont coupés
$r = energyManagerEngine::decide(base(array('pv' => 0, 'equips' => array(rad(1, array('is_on' => true))))));
check('nuit : radiateur coupé', find_action($r, 'equip', array('id' => 1, 'cmd' => 'off')) !== null);

// 12. Injection critique et rien à démarrer : limitation Hoymiles
$r = energyManagerEngine::decide(base(array('grid' => -1500, 'pv' => 5000, 'equips' => array())));
$a = find_action($r, 'hoymiles');
check('injection critique sans consommateur : Hoymiles bridé', $a !== null && $a['value'] <= 3600);

// 13. Injection critique mais un consommateur peut démarrer : pas de limitation
$r = energyManagerEngine::decide(base(array('grid' => -1500, 'pv' => 5000, 'equips' => array(rad(1)))));
check('injection critique + consommateur dispo : pas de limitation', find_action($r, 'hoymiles') === null);
check('injection critique + consommateur dispo : démarrage', find_action($r, 'equip', array('cmd' => 'on')) !== null);

// 14. Remontée de la limite quand il n'y a plus d'injection
$r = energyManagerEngine::decide(base(array('grid' => 50, 'pv' => 3000, 'hoy_limit' => 3000)));
$a = find_action($r, 'hoymiles');
check('limite relevée après soutirage', $a !== null && $a['value'] > 3000);

// 15. Le matin : limite remise au max
$r = energyManagerEngine::decide(base(array('pv' => 0, 'hoy_limit' => 2000)));
check('plus de soleil : limite remise à 7600', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 7600);

// 16. Priorité Tesla : charge batterie à 0 W
$tesla = array('id' => 90, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => true, 'power_real' => 1500, 'power_theo' => 3680, 'priority' => 1);
$r = energyManagerEngine::decide(base(array('cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($tesla))));
check('priorité Tesla : charge batterie à 0', ($a = find_action($r, 'charge_limit')) !== null && $a['value'] == 0);

// 17. Priorité batterie : la charge reste libre
$r = energyManagerEngine::decide(base(array('cfg' => array('tesla_priority' => 'battery'), 'equips' => array($tesla))));
check('priorité batterie : charge libre', find_action($r, 'charge_limit') === null);

// 18. Batterie à la cible 95 % : charge stoppée
$r = energyManagerEngine::decide(base(array('soc' => 96, 'cfg' => array('batt_target' => 95))));
check('cible 95 % atteinte : charge stoppée', ($a = find_action($r, 'charge_limit')) !== null && $a['value'] == 0);

// 19. Cible 95 % + surplus critique : le tampon est utilisé
$r = energyManagerEngine::decide(base(array('soc' => 96, 'grid' => -1500, 'charge_limit' => 0, 'cfg' => array('batt_target' => 95))));
check('surplus critique : tampon batterie rouvert', ($a = find_action($r, 'charge_limit')) !== null && $a['value'] == 3680);

// 20. Ballon : en HC d'après-midi, soleil insuffisant, eau froide : chauffe EDF
$r = energyManagerEngine::decide(base(array('pv' => 100, 'hc_afternoon' => true, 'equips' => array(ecs()))));
check('ballon : chauffe en HC d\'après-midi sans soleil', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 21. Ballon : déjà chaud pour la journée
$r = energyManagerEngine::decide(base(array('pv' => 100, 'hc_afternoon' => true, 'equips' => array(ecs(array('done_today' => true, 'water_temp' => 56))))));
check('ballon terminé et chaud : pas de chauffe', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 22. Ballon : en HC d'après-midi mais le soleil est là (surplus mobilisable : la batterie en absorbe 3000 W), pas d'EDF
$r = energyManagerEngine::decide(base(array('pv' => 4000, 'grid' => 0, 'batt' => 3000, 'hc_afternoon' => true, 'equips' => array(ecs()))));
$a = find_action($r, 'equip', array('id' => 50, 'cmd' => 'on'));
check('ballon : le soleil suffit, chauffe solaire et pas EDF', $a !== null && strpos($a['reason'], 'heure creuse') === false);

// 22b. Ballon prioritaire sur la batterie, même à SOC 60 % et sans injection
$r = energyManagerEngine::decide(base(array('soc' => 60, 'pv' => 4000, 'grid' => 0, 'batt' => 3000, 'equips' => array(ecs()))));
check('ballon prioritaire sur la batterie (SOC 60 %, aucune injection)', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 22c. Ballon lancé en HC : on le laisse chauffer jusqu'au thermostat, même au-dessus de 52 °C
$r = energyManagerEngine::decide(base(array('pv' => 100, 'batt' => 0, 'hc_afternoon' => true,
	'equips' => array(ecs(array('is_on' => true, 'power_real' => 2300, 'water_temp' => 55))))));
check('ballon en HC : pas d\'arrêt avant le thermostat', find_action($r, 'equip', array('id' => 50, 'cmd' => 'off')) === null);

// 22d. Ballon prioritaire sur la Tesla (priorité Tesla) : il démarre, teslasmartcharge s'ajustera
$teslaCharge = array('id' => 91, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => true, 'power_real' => 3000, 'power_theo' => 7000, 'priority' => 1);
$r = energyManagerEngine::decide(base(array('pv' => 4000, 'grid' => 0, 'batt' => 0, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaCharge, ecs()))));
check('ballon prioritaire sur la Tesla', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 22e. Le message de décision explique pourquoi un consommateur attend
$r = energyManagerEngine::decide(base(array('grid' => -300, 'batt' => 0, 'equips' => array(rad(1)))));
check('décision : explique l\'attente', strpos($r['info']['decision'], 'attend') !== false);

// 23. Ballon : priorité sur les radiateurs
$r = energyManagerEngine::decide(base(array('grid' => -3000, 'equips' => array(rad(1, array('priority' => 1)), ecs()))));
check('ballon avant les radiateurs', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 24. Tesla solaire : on laisse sa marge avant de démarrer un autre consommateur
$r = energyManagerEngine::decide(base(array('grid' => -900, 'equips' => array($tesla, rad(1)))));
check('Tesla solaire : marge de 300 W respectée', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 24b. Priorité Tesla : on attend qu'elle monte plutôt que de donner le surplus à un radiateur
$teslaSolaire = array('id' => 90, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => true, 'power_real' => 0, 'power_theo' => 7000, 'priority' => 1);
$r = energyManagerEngine::decide(base(array('grid' => -1200, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire, rad(1)), 'mem' => array('inj_any_since' => 1000000 - 30))));
check('priorité Tesla : le radiateur attend', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 24c. Priorité Tesla : si elle ne réagit pas au bout du délai, on agit
$r = energyManagerEngine::decide(base(array('grid' => -1200, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire, rad(1)), 'mem' => array('inj_any_since' => 1000000 - 300))));
check('Tesla sans réaction après le délai : le radiateur démarre', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

// 24d. Priorité Tesla : une grosse injection n'attend pas
$r = energyManagerEngine::decide(base(array('grid' => -2500, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire, rad(1)), 'mem' => array('inj_any_since' => 1000000 - 10))));
check('grosse injection : on n\'attend pas la Tesla', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

// 24e. Tesla à déclencher : 500 W d'injection tolérés (+200 de bande), pas de bridage en dessous
$r = energyManagerEngine::decide(base(array('grid' => -650, 'pv' => 4000, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire), 'mem' => array('inj_any_since' => 1000000 - 20))));
check('Tesla à déclencher : 650 W d'injection tolérés, Hoymiles non bridé', find_action($r, 'hoymiles') === null);
$r = energyManagerEngine::decide(base(array('grid' => -800, 'pv' => 4000, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire), 'mem' => array('inj_any_since' => 1000000 - 20))));
check('Tesla à déclencher : 800 W > budget 500 W, réduction jusqu'au budget (pas à zéro)', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 3700);

// 24f. Tesla déjà à fond : plus de place, les autres consommateurs peuvent démarrer
$teslaPleine = array_merge($teslaSolaire, array('power_real' => 6900));
$r = energyManagerEngine::decide(base(array('grid' => -1200, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaPleine, rad(1)))));
check('Tesla à fond : le radiateur peut démarrer', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

// 26. Réserve de nuit : SOC trop bas, le ballon ne prend pas le solaire destiné à la batterie
$r = energyManagerEngine::decide(base(array('soc' => 40, 'pv' => 4000, 'grid' => 0, 'batt' => 3000, 'equips' => array(ecs()))));
check('réserve de nuit : SOC 40 % < 50 %, le ballon n\'est pas prioritaire sur la batterie', find_action($r, 'equip', array('cmd' => 'on')) === null);
check('réserve de nuit : la décision l\'explique', strpos($r['info']['decision'], 'réserve de nuit') !== false);

// 26b. SOC bas mais vraie injection (batterie déjà au maximum de charge) : le ballon l'utilise
$r = energyManagerEngine::decide(base(array('soc' => 40, 'pv' => 6000, 'grid' => -2500, 'batt' => 3680, 'equips' => array(ecs()))));
check('SOC bas + injection réelle : le ballon démarre', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 26c. SOC bas, heure creuse d'après-midi, pas de soleil : EDF chauffe
$r = energyManagerEngine::decide(base(array('soc' => 40, 'pv' => 100, 'batt' => 0, 'hc_afternoon' => true, 'equips' => array(ecs()))));
check('SOC bas + HC d\'après-midi : chauffe EDF', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 26d. Avec une prévision solaire généreuse, le SOC projeté au soir dépasse la réserve : le ballon passe avant la batterie
$r = energyManagerEngine::decide(base(array('soc' => 40, 'minute' => 12 * 60, 'forecast_kwh' => 12, 'pv' => 4000, 'grid' => 0, 'batt' => 3000, 'equips' => array(ecs()))));
check('prévision 12 kWh : SOC projeté suffisant, ballon prioritaire', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null && $r['info']['ecs_may_take'] === 1);

// 26e. Prévision faible : réserve non garantie
$r = energyManagerEngine::decide(base(array('soc' => 40, 'minute' => 12 * 60, 'forecast_kwh' => 2, 'pv' => 4000, 'grid' => 0, 'batt' => 3000, 'equips' => array(ecs()))));
check('prévision 2 kWh : ballon pas prioritaire sur la batterie', find_action($r, 'equip', array('cmd' => 'on')) === null && $r['info']['ecs_may_take'] === 0);

// 26f. Le ballon alimenté en solaire ne doit pas décharger la batterie sous la réserve
$r = energyManagerEngine::decide(base(array('soc' => 40, 'pv' => 1500, 'grid' => 0, 'batt' => -800,
	'equips' => array(ecs(array('is_on' => true, 'power_real' => 2300))))));
$a = find_action($r, 'equip', array('id' => 50, 'cmd' => 'off'));
check('batterie qui se décharge sous la réserve : ballon coupé', $a !== null && strpos($a['reason'], 'réserve de nuit') !== false);

// 26g. SOC suffisant : la batterie peut compenser (pas de coupure)
$r = energyManagerEngine::decide(base(array('soc' => 80, 'pv' => 1500, 'grid' => 0, 'batt' => -800,
	'equips' => array(ecs(array('is_on' => true, 'power_real' => 2300))))));
check('SOC suffisant : le ballon peut être compensé par la batterie', find_action($r, 'equip', array('id' => 50, 'cmd' => 'off')) === null);

// 27. Échéance du ballon à 16 h : non chaud => OFF + alerte (une seule fois par jour côté plugin)
$r = energyManagerEngine::decide(base(array('minute' => 16 * 60 + 5, 'pv' => 3000, 'batt' => 0,
	'equips' => array(ecs(array('is_on' => true, 'power_real' => 2300, 'water_temp' => 45))))));
check('échéance 16 h : ballon froid mis sur OFF', find_action($r, 'equip', array('id' => 50, 'cmd' => 'off')) !== null);
$al = null;
foreach ($r['alerts'] as $x) { if (strpos($x['key'], 'ecs_deadline') === 0) { $al = $x; } }
check('échéance 16 h : alerte unique', $al !== null && !empty($al['once']));

$r = energyManagerEngine::decide(base(array('minute' => 16 * 60 + 5, 'pv' => 3000, 'batt' => 0,
	'equips' => array(ecs(array('water_temp' => 56))))));
$none = true;
foreach ($r['alerts'] as $x) { if (strpos($x['key'], 'ecs_deadline') === 0) { $none = false; } }
check('échéance 16 h : ballon chaud, pas d\'alerte', $none);

$r = energyManagerEngine::decide(base(array('minute' => 15 * 60, 'pv' => 100, 'hc_afternoon' => true, 'equips' => array(ecs(array('water_temp' => 45))))));
check('avant 16 h : pas d\'alerte et chauffe EDF en HC', count($r['alerts']) === 0 && find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 27b. Après l'échéance, la réponse Discord « Oui » (force_edf) permet de compléter
$r = energyManagerEngine::decide(base(array('minute' => 17 * 60, 'pv' => 100, 'equips' => array(ecs(array('water_temp' => 45, 'force_edf' => true))))));
check('après l\'échéance : complément EDF validé', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

// 28. Hoymiles : une charge arrive (Tesla en mode solaire avec de la place) => limite remise au max IMMÉDIATEMENT
$teslaBranchee = array('id' => 92, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => true, 'power_real' => 0, 'power_theo' => 7000, 'priority' => 1);
$r = energyManagerEngine::decide(base(array('pv' => 2000, 'grid' => -100, 'hoy_limit' => 2000, 'equips' => array($teslaBranchee),
	'mem' => array('last_hoy_write' => 1000000 - 60))));
check('Tesla à déclencher : on libère 500 W (pas tout d'un coup)', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 2500);

// 28b. Rien à alimenter (batterie pleine, pas de charge) : la limite bridée reste bridée
$r = energyManagerEngine::decide(base(array('soc' => 100, 'pv' => 2000, 'grid' => -100, 'batt' => 0, 'hoy_limit' => 2000, 'equips' => array(),
	'mem' => array('last_hoy_write' => 1000000 - 500))));
check('rien à alimenter : la limite bridée reste', find_action($r, 'hoymiles') === null);

// 28c. Un consommateur voudrait démarrer et le surplus masqué par le bridage suffit : limite remise au max
$r = energyManagerEngine::decide(base(array('soc' => 100, 'pv' => 2000, 'grid' => -100, 'batt' => 0, 'hoy_limit' => 2000, 'equips' => array(rad(1)),
	'mem' => array('hoy_hidden' => 1500, 'last_hoy_write' => 1000000 - 5))));
check('consommateur en attente + surplus masqué : limite au max', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 7600);

// 28d. Même cas sans surplus masqué : on ne rouvre pas pour rien (pas de bascule)
$r = energyManagerEngine::decide(base(array('soc' => 100, 'pv' => 2000, 'grid' => -100, 'batt' => 0, 'hoy_limit' => 2000, 'equips' => array(rad(1)),
	'mem' => array('hoy_hidden' => 0, 'last_hoy_write' => 1000000 - 5))));
check('consommateur en attente sans surplus masqué : limite inchangée', find_action($r, 'hoymiles') === null);

// 28e. Ballon > 50 °C : il n'est plus un consommateur à alimenter
$r = energyManagerEngine::decide(base(array('soc' => 100, 'pv' => 5000, 'grid' => -1500, 'batt' => 0,
	'equips' => array(ecs(array('water_temp' => 51, 'min_temp' => 55))), 'mem' => array('last_start' => 1000000))));
check('ballon > 50 °C et injection critique : limite bridée', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] < 7600);

// 29. Tesla : jamais bridée. En charge à 1500 W (plage 460 W à 7200 W), grosse injection : la limite Hoymiles reste au max
$teslaEnCharge = array('id' => 93, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => true, 'power_real' => 1500, 'power_theo' => 7200, 'priority' => 1);
$r = energyManagerEngine::decide(base(array('pv' => 6500, 'grid' => -2000, 'equips' => array($teslaEnCharge),
	'mem' => array('inj_any_since' => 1000000 - 400, 'inj_since' => 1000000 - 100))));
check('Tesla en charge (budget 1000 W) : réduction seulement jusqu'au budget', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 5500);

// 29b. Tesla bloquée (injection critique depuis plus de 5 min) : on finit par brider pour ne pas injecter
$r = energyManagerEngine::decide(base(array('pv' => 6500, 'grid' => -2000, 'equips' => array($teslaEnCharge),
	'mem' => array('inj_any_since' => 1000000 - 900, 'inj_since' => 1000000 - 400))));
check('Tesla bloquée > 5 min : limitation Hoymiles', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] < 7600);

// 29c. Tesla en mode solaire mais pas encore démarrée : la batterie garde sa charge (teslasmartcharge compte la charge batterie dans son surplus)
$teslaAttente = array_merge($teslaEnCharge, array('power_real' => 0));
$r = energyManagerEngine::decide(base(array('pv' => 3000, 'grid' => 0, 'batt' => 2000, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaAttente))));
check('Tesla pas encore démarrée : la charge batterie n\'est pas coupée', find_action($r, 'charge_limit') === null);

// 29d. Tesla en charge (>= 460 W) et priorité Tesla : batterie à 0 W
$r = energyManagerEngine::decide(base(array('pv' => 3000, 'grid' => 0, 'batt' => 500, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaEnCharge))));
check('Tesla en charge + priorité Tesla : charge batterie à 0 W', ($a = find_action($r, 'charge_limit')) !== null && $a['value'] == 0);

// 30. Ballon "terminé" à midi mais l'eau est redescendue sous le minimum avant 16 h : on le rechauffe
$r = energyManagerEngine::decide(base(array('minute' => 13 * 60, 'pv' => 4000, 'grid' => 0, 'batt' => 3000,
	'equips' => array(ecs(array('done_today' => true, 'water_temp' => 45))))));
check('ballon terminé mais froid (solaire) : rechauffe', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

$r = energyManagerEngine::decide(base(array('minute' => 15 * 60, 'pv' => 100, 'batt' => 0, 'hc_afternoon' => true,
	'equips' => array(ecs(array('done_today' => true, 'water_temp' => 45))))));
check('ballon terminé mais froid (HC d'après-midi) : chauffe EDF', find_action($r, 'equip', array('id' => 50, 'cmd' => 'on')) !== null);

$r = energyManagerEngine::decide(base(array('minute' => 16 * 60 + 5, 'pv' => 3000, 'batt' => 0,
	'equips' => array(ecs(array('done_today' => true, 'water_temp' => 45))))));
$al = null;
foreach ($r['alerts'] as $x) { if (strpos($x['key'], 'ecs_deadline') === 0) { $al = $x; } }
check('ballon terminé mais froid à l'échéance : pas de chauffe et alerte', find_action($r, 'equip', array('cmd' => 'on')) === null && $al !== null);

// 31. Tesla branchée : passage automatique en Solaire, une seule fois par branchement
$teslaStandby = array('id' => 95, 'type' => 'tesla', 'name' => 'Tesla', 'mode' => 'auto', 'active' => false, 'power_real' => 0, 'power_theo' => 7200,
	'priority' => 1, 'tesla_mode' => 'Stand-By', 'plugged' => true, 'plug_handled' => false);
$r = energyManagerEngine::decide(base(array('equips' => array($teslaStandby))));
check('Tesla branchée en Stand-By : passage en Solaire', ($a = find_action($r, 'tesla_mode', array('id' => 95))) !== null && $a['value'] === 'Solaire');

$r = energyManagerEngine::decide(base(array('equips' => array(array_merge($teslaStandby, array('plug_handled' => true))))));
check('Tesla déjà prise en charge pour ce branchement : rien', find_action($r, 'tesla_mode') === null);

$r = energyManagerEngine::decide(base(array('equips' => array(array_merge($teslaStandby, array('plugged' => false))))));
check('Tesla débranchée : rien', find_action($r, 'tesla_mode') === null);

$r = energyManagerEngine::decide(base(array('equips' => array(array_merge($teslaStandby, array('mode' => 'off', 'tesla_mode' => 'Non actif'))))));
check('Tesla « Non actif » : on n'y touche pas', find_action($r, 'tesla_mode') === null);

$r = energyManagerEngine::decide(base(array('equips' => array(array_merge($teslaStandby, array('tesla_mode' => 'Planifié'))))));
check('Tesla « Planifié » choisi par l'utilisateur : on n'y touche pas', find_action($r, 'tesla_mode') === null);

$r = energyManagerEngine::decide(base(array('equips' => array(array_merge($teslaStandby, array('plugged' => null))))));
check('Tesla : branchement inconnu : rien', find_action($r, 'tesla_mode') === null);

// 32. Agressivité grande (2000 W) : paliers plus larges une fois la charge en cours
$teslaGrande = array_merge($teslaEnCharge, array('aggr_w' => 2000, 'power_real' => 3000));
$r = energyManagerEngine::decide(base(array('pv' => 7000, 'grid' => -3000, 'equips' => array($teslaGrande),
	'mem' => array('tesla_prev_w' => 3000, 'inj_since' => 1000000 - 30))));
check('agressivité grande : budget 2000 W', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 6000);

// 32b. Même agressivité mais Tesla pas encore en charge : seulement 500 W pour la déclencher
$teslaGrandeAttente = array_merge($teslaGrande, array('power_real' => 0));
$r = energyManagerEngine::decide(base(array('pv' => 4000, 'grid' => -800, 'equips' => array($teslaGrandeAttente))));
check('agressivité grande mais Tesla à l'arrêt : budget 500 W', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 3700);

// 32c. Tesla en charge qui a consommé ce qui était libéré : palier suivant (+budget)
$teslaMoyenne = array_merge($teslaEnCharge, array('aggr_w' => 1000, 'power_real' => 3000));
$r = energyManagerEngine::decide(base(array('pv' => 5000, 'grid' => -100, 'hoy_limit' => 5000, 'equips' => array($teslaMoyenne),
	'mem' => array('tesla_prev_w' => 3000, 'last_hoy_write' => 1000000 - 60))));
check('Tesla consomme la puissance libérée : palier de +1000 W', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 6000);

// 32d. Réglage manuel de la charge Tesla à la hausse : limite au maximum immédiatement
$teslaManuelle = array_merge($teslaEnCharge, array('power_real' => 5000));
$r = energyManagerEngine::decide(base(array('pv' => 3000, 'grid' => 0, 'hoy_limit' => 3000, 'equips' => array($teslaManuelle),
	'mem' => array('tesla_prev_w' => 0, 'last_hoy_write' => 1000000 - 5))));
check('charge Tesla augmentée à la main : limite au maximum', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 7600);

// 32e. Marge EDF de teslasmartcharge (négative = soutirage toléré) : un soutirage inférieur ne déclenche pas de réaction
$teslaMarge = array_merge($teslaBranchee, array('margin' => -300));
$r = energyManagerEngine::decide(base(array('pv' => 3000, 'grid' => 200, 'hoy_limit' => 3000, 'equips' => array($teslaMarge),
	'mem' => array('tesla_prev_w' => 0, 'last_hoy_write' => 1000000 - 60))));
check('marge EDF -300 W : soutirage de 200 W toléré (pas de retour au maximum)', ($a = find_action($r, 'hoymiles')) === null || $a['value'] != 7600);
$r = energyManagerEngine::decide(base(array('pv' => 3000, 'grid' => 200, 'hoy_limit' => 3000, 'equips' => array($teslaBranchee),
	'mem' => array('tesla_prev_w' => 0, 'last_hoy_write' => 1000000 - 60))));
check('sans marge : soutirage de 200 W, limite au maximum', ($a = find_action($r, 'hoymiles')) !== null && $a['value'] == 7600);

// 32f. Pas d'alerte « injection critique » pendant que la Tesla monte dans son budget
$r = energyManagerEngine::decide(base(array('pv' => 5000, 'grid' => -1500, 'equips' => array($teslaGrande),
	'mem' => array('tesla_prev_w' => 3000, 'inj_since' => 1000000 - 60))));
$k = false;
foreach ($r['alerts'] as $x) { if ($x['key'] === 'injection') { $k = true; } }
check('Tesla dans son budget : pas d'alerte d'injection critique', !$k);
$r = energyManagerEngine::decide(base(array('pv' => 5000, 'grid' => -1500, 'equips' => array(), 'mem' => array('inj_since' => 1000000 - 60))));
$k = false;
foreach ($r['alerts'] as $x) { if ($x['key'] === 'injection') { $k = true; } }
check('sans Tesla : alerte d'injection critique', $k);

// 25. Plages d'heures creuses (JSON du sélecteur)
$slots = energyManagerEngine::parseSlots('00:50-06:50;14:20-16:20');
check('plages : 2 plages lues', count($slots) === 2);
check('plages : 15h = HC', energyManagerEngine::slotAt($slots, 15 * 60) === 'HC');
check('plages : 10h = HP', energyManagerEngine::slotAt($slots, 10 * 60) === 'HP');
check('plages : 3h = HC', energyManagerEngine::slotAt($slots, 3 * 60) === 'HC');
check('plages : 00h30 = HP', energyManagerEngine::slotAt($slots, 30) === 'HP');
$nuit = energyManagerEngine::parseSlots('22:00-06:00');
check('plage à cheval sur minuit : 23h = HC', energyManagerEngine::slotAt($nuit, 23 * 60) === 'HC');
check('plage à cheval sur minuit : 7h = HP', energyManagerEngine::slotAt($nuit, 7 * 60) === 'HP');
check('plages : texte invalide = aucune plage', count(energyManagerEngine::parseSlots('n\'importe quoi')) === 0);
check('plages : valeur déjà décodée par Jeedom = aucune plage, sans erreur', count(energyManagerEngine::parseSlots(array(array('s' => '00:50')))) === 0);
check('plages : format 22h00 accepté', count(energyManagerEngine::parseSlots('22h00-06h00')) === 1);

echo PHP_EOL . ($fail === 0 ? 'Tous les tests passent.' : $fail . ' test(s) en échec.') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
