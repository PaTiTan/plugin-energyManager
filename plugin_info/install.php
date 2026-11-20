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

function energyManager_install() {
	// Sécurité : le plugin démarre TOUJOURS en mode simulation (aucun ordre envoyé).
	config::save('simulation', 1, 'energyManager');
	config::save('tesla_priority', 'tesla', 'energyManager');
	config::save('batt_target', 100, 'energyManager');
	energyManager::ensureMainEquipment();
}

function energyManager_update() {
	energyManager::ensureMainEquipment();
	foreach (eqLogic::byType('energyManager') as $eq) {
		$eq->save();
	}
}

function energyManager_remove() {
	$cron = cron::byClassAndFunction('energyManager', 'daemon');
	if (is_object($cron)) {
		$cron->remove();
	}
}
