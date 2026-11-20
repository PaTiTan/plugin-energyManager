# Changelog

## 0.3.2

- Correctif : la puissance théorique et la puissance mesurée d'un ballon ou d'un radiateur n'étaient pas enregistrées (les champs de la Tesla utilisaient les mêmes clés et écrasaient la valeur à la sauvegarde). La Tesla a maintenant ses propres champs ; ses anciennes valeurs sont reprises. **Ressaisir la puissance théorique des ballons et radiateurs après la mise à jour.**

## 0.3.1

- Ballon : action prioritaire. Il démarre dès que le surplus mobilisable (injection + puissance de charge de la batterie + puissance de la Tesla) atteint 70 % de sa puissance, quel que soit le SOC, sans attendre la batterie ni la Tesla.
- Ballon en heure creuse d'après-midi : si le soleil ne suffit pas, chauffe EDF jusqu'à ce que le thermostat coupe (il n'est plus arrêté à 52 °C).
- Le message de décision explique pourquoi un consommateur attend (surplus disponible et besoin).

## 0.3.0

- Page de simulation complète (bouton « Simulateur » de la page des équipements) : widget powerFlow animé, jauges actionnables (solaire disponible, talon maison, SOC, eau du ballon, température des pièces), heure et vitesse réglables, courbe solaire de la journée.
- Le simulateur exécute le vrai moteur de décision avec la configuration réelle (équipements, priorités, plages HC) ; modèle simplifié de la batterie, du ballon, des pièces et de teslasmartcharge. Décisions, ordres et alertes affichés en direct. Rien n'est lu ni envoyé sur l'installation.
- Possibilité de tester : interrupteurs de pilotage, modes des équipements, priorité Tesla/Batterie, cible 95/100 %, panne de mesure, réponse Discord « Compléter avec EDF ».
- Jeu d'équipements de démonstration si aucun équipement n'est encore créé.

## 0.2.2

- Interrupteur général rouge/vert dans le widget : vert = energyManager pilote, rouge = il mesure et affiche seulement (aucun ordre, aucune alerte).
- Un voyant rouge/vert cliquable par consommateur : vert = piloté par le plugin, rouge = laissé de côté. Commandes `Piloté ON/OFF` disponibles sur chaque équipement.
- Le SOC batterie n'est plus contrôlé en âge par défaut (paramètre « Âge maximal du SOC batterie », 0 = aucun contrôle). Seule son absence bloque le pilotage.

## 0.2.1

- Correctif : les plages d'heures creuses n'étaient pas mémorisées (Jeedom décode automatiquement les valeurs ressemblant à du JSON). Nouveau format texte.
- Lecture du cache plus robuste.

## 0.2.0

- Intégration de teslasmartcharge : energyManager commande `set_mode` et `set_priorite_charge` et lit `mode_actuel`.
- Priorité Tesla : les autres consommateurs et le bridage Hoymiles attendent que la voiture monte en puissance.
- Plages d'heures creuses multiples avec sélecteur d'heure (remplace la saisie texte).
- Widget : sens du flux réseau corrigé (le soutirage positif va du réseau vers la maison).

## 0.1.0

- Structure du plugin au format market (info.json, install, configuration, démon).
- Moteur de décision pur et testé : surplus, délestage, limitation Hoymiles en dernier recours, tampon batterie 95 %, priorité Tesla/batterie, ballon.
- Équipements : consommateur simple, ballon, Tesla, gestionnaire.
- Mode simulation par défaut, simulateur de décisions.
- Alertes Discord en boucle, vérification des retours Victron/Ahoy.
- Widget powerFlow (dashboard et mobile).
