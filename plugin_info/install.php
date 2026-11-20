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
	try {
		energyManager::ensureMainEquipment();
	} catch (Throwable $e) {
		log::add('energyManager', 'error', 'Installation : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
	}
}

function energyManager_update() {
	// Sécurité : une mise à jour ne doit jamais laisser le mode simulation indéfini (clé absente = simulation).
	$sim = config::byKey('simulation', 'energyManager', '');
	if ($sim === '' || $sim === null) {
		config::save('simulation', 1, 'energyManager');
	}
	// Une erreur sur un équipement ne doit jamais faire échouer (et donc désactiver) le plugin : elle est journalisée.
	try {
		energyManager::ensureMainEquipment();
	} catch (Throwable $e) {
		log::add('energyManager', 'error', 'Mise à jour : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
	}
	foreach (eqLogic::byType('energyManager') as $eq) {
		try {
			$eq->save();
		} catch (Throwable $e) {
			log::add('energyManager', 'error', 'Mise à jour de « ' . $eq->getName() . ' » : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}
}

function energyManager_remove() {
	$cron = cron::byClassAndFunction('energyManager', 'daemon');
	if (is_object($cron)) {
		$cron->remove();
	}
}
