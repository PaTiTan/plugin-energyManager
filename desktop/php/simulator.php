<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>

<div id="emSim">
	<div class="ems-top">
		<a class="btn btn-default btn-sm" href="index.php?v=d&m=energyManager&p=energyManager"><i class="fas fa-arrow-left"></i> {{Retour aux équipements}}</a>
		<span class="ems-title"><i class="fas fa-flask"></i> {{Simulateur}}</span>
		<span class="ems-badge">{{AUCUN ORDRE N'EST ENVOYÉ : l'installation n'est ni lue ni commandée}}</span>
		<span id="ems-demo" class="ems-badge ems-badge-warn" style="display:none">{{Équipements de démonstration (aucun équipement créé)}}</span>
	</div>

	<div class="ems-bar">
		<a class="btn btn-sm btn-success" id="ems-play" title="{{Lecture / pause}}"><i class="fas fa-pause"></i></a>
		<label>{{Vitesse}}
			<select id="ems-speed" class="form-control input-sm">
				<option value="1">×1 ({{temps réel}})</option>
				<option value="10" selected>×10</option>
				<option value="30">×30</option>
				<option value="60">×60</option>
			</select>
		</label>
		<span class="ems-clock" id="ems-clock">12:00:00</span>
		<span class="ems-slot" id="ems-slot">HP</span>
		<input type="range" id="ems-time" min="0" max="1439" step="5" value="720" title="{{Heure simulée}}"/>
		<a class="btn btn-sm btn-default" id="ems-reset"><i class="fas fa-undo"></i> {{Réinitialiser}}</a>
	</div>

	<div class="ems-grid">
		<div class="ems-side" id="ems-left"></div>

		<div class="ems-center">
			<div class="ems-panel">
				<div class="ems-master"><span class="ems-led ems-g" id="ems-active" title="{{Pilotage par energyManager (simulé) : cliquer pour activer / désactiver}}"></span> <span id="ems-activetxt"></span></div>
				<svg viewBox="0 0 560 250" xmlns="http://www.w3.org/2000/svg">
					<path class="wire" d="M120 45 H280 V105"/>
					<path class="wire" d="M120 125 H210"/>
					<path class="wire" d="M280 145 V195 H210"/>
					<path class="wire" d="M350 125 H440"/>
					<path class="flow" id="ems-fpv" stroke="#f5a623" d="M120 45 H280 V105"/>
					<path class="flow" id="ems-fgrid" stroke="#4a90d9" d="M120 125 H210"/>
					<path class="flow" id="ems-fbat" stroke="#3ecf63" d="M280 145 V195 H210"/>
					<path class="flow" id="ems-fhouse" stroke="#4ec9b0" d="M350 125 H440"/>
					<g class="node pv" transform="translate(10,15)"><rect width="110" height="60"/><text class="lbl" x="55" y="18">☀ SOLAIRE</text><text class="val" id="ems-pv" x="55" y="40">-- W</text><text class="lbl" id="ems-pvavail" x="55" y="54">dispo -- W</text></g>
					<g class="node grid" transform="translate(10,95)"><rect width="110" height="60"/><text class="lbl" x="55" y="18">⚡ RÉSEAU EDF</text><text class="val" id="ems-grid" x="55" y="45">-- W</text></g>
					<g class="node" transform="translate(210,105)"><rect width="140" height="40"/><text class="lbl" x="70" y="16">MULTIPLUS II</text><text id="ems-inj" x="70" y="33" style="font-size:12px">injection 0 W</text></g>
					<g class="node house" transform="translate(440,95)"><rect width="110" height="60"/><text class="lbl" x="55" y="18">🏠 MAISON</text><text class="val" id="ems-house" x="55" y="45">-- W</text></g>
					<g class="node bat" transform="translate(10,175)"><rect width="200" height="60"/><text class="lbl" x="100" y="18">🔋 BATTERIE 16 kWh</text><text class="val" id="ems-bat" x="100" y="45">-- % · -- W</text></g>
					<g class="node" transform="translate(300,175)"><rect width="250" height="60"/><text class="lbl" x="125" y="18">LIMITES</text><text id="ems-lim" x="125" y="45" style="font-size:13px">Hoymiles -- · charge --</text></g>
				</svg>

				<div class="ems-cons" id="ems-cons"></div>

				<div class="ems-ctl">
					<span>{{Priorité}} :</span>
					<a class="btn btn-xs btn-default" id="ems-bpt">Tesla</a>
					<a class="btn btn-xs btn-default" id="ems-bpb">Batterie</a>
					<span>{{Cible}} :</span>
					<a class="btn btn-xs btn-default" id="ems-b95">95 %</a>
					<a class="btn btn-xs btn-default" id="ems-b100">100 %</a>
				</div>
				<div class="ems-ctl">
					<label><input type="checkbox" id="ems-auto"/> {{Courbe solaire de la journée (le curseur ☀ donne le pic à 13 h)}}</label>
					<label><input type="checkbox" id="ems-forecast"/> {{Utiliser la prévision solaire restante (jauge 🔮)}}</label>
					<label><input type="checkbox" id="ems-heat" checked/> {{Saison de chauffe}}</label>
					<label><input type="checkbox" id="ems-stale"/> {{Simuler une panne de mesure (donnée obsolète)}}</label>
				</div>
			</div>

			<div class="ems-decision" id="ems-decision">{{En attente du premier cycle…}}</div>
			<div class="ems-decision" id="ems-night" style="border-left-color:#8e7cc3"></div>
			<div class="ems-logtitle"><i class="fas fa-stream"></i> {{Décisions et messages du plugin (temps simulé)}}</div>
			<div class="ems-log" id="ems-log"></div>
		</div>

		<div class="ems-side" id="ems-right"></div>
	</div>
</div>

<?php include_file('desktop', 'simulator', 'css', 'energyManager'); ?>
<?php include_file('desktop', 'simulator', 'js', 'energyManager'); ?>
