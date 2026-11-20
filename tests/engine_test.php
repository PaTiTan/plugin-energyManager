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
$r = energyManagerEngine::decide(base(array('pv' => 100, 'hc_afternoon' => true, 'equips' => array(ecs(array('done_today' => true))))));
check('ballon terminé : pas de chauffe', find_action($r, 'equip', array('cmd' => 'on')) === null);

// 22. Ballon : en HC d'après-midi mais le soleil est là, pas d'EDF
$r = energyManagerEngine::decide(base(array('pv' => 4000, 'grid' => 0, 'hc_afternoon' => true, 'equips' => array(ecs()))));
check('ballon : le soleil suffit, pas de chauffe EDF', find_action($r, 'equip', array('cmd' => 'on')) === null);

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

// 24e. Priorité Tesla : pas de bridage Hoymiles pendant qu'elle monte
$r = energyManagerEngine::decide(base(array('grid' => -800, 'pv' => 4000, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaSolaire), 'mem' => array('inj_any_since' => 1000000 - 20))));
check('priorité Tesla : Hoymiles non bridé pendant la montée', find_action($r, 'hoymiles') === null);

// 24f. Tesla déjà à fond : plus de place, les autres consommateurs peuvent démarrer
$teslaPleine = array_merge($teslaSolaire, array('power_real' => 6900));
$r = energyManagerEngine::decide(base(array('grid' => -1200, 'cfg' => array('tesla_priority' => 'tesla'), 'equips' => array($teslaPleine, rad(1)))));
check('Tesla à fond : le radiateur peut démarrer', find_action($r, 'equip', array('id' => 1, 'cmd' => 'on')) !== null);

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
