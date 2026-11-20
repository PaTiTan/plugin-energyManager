# Changelog

## 0.8.1

- **La Tesla n'attend plus jamais un niveau de batterie.** En priorité Batterie (teslasmartcharge « maison »), la batterie passe d'abord mais la voiture prend tout ce que la batterie n'absorbe pas (l'injection) et charge dès 2 A (460 W). Le simulateur reproduit cette règle. Nécessite teslasmartcharge 1.3.0-beta.

## 0.8.0

- **Extinction propre d'un équipement que l'on cesse de piloter** (voyant rouge ou commande « Piloté OFF ») : radiateurs et ballon sont mis à OFF ; la Tesla passe en Stand-By (ce qui arrête la charge en cours) puis en « Non actif » (teslasmartcharge ne fait plus rien : sans l'étape Stand-By, la charge en cours aurait continué). Même principe prévu pour la climatisation. Rien n'est envoyé en mode simulation. Quand la Tesla redevient pilotée, elle repasse en Stand-By et energyManager la remet en Solaire au branchement.
- Widget et simulateur : la case « Limites » est sur deux lignes (le texte débordait).

## 0.7.3

- Simulateur : la tuile de la Tesla affiche le calcul de teslasmartcharge (surplus = batterie − réseau + voiture, courant visé, mini 2 A / 460 W, délai de confirmation, plafond de puissance maximale, priorité maison). Hausses confirmées en 10 s comme dans teslasmartcharge 1.2.

## 0.7.2

- **Correctif majeur : le plugin se désactivait.** L'interrupteur « Pilotage » enregistrait son état dans la clé de configuration `active`, qui est aussi celle que Jeedom utilise pour l'activation du plugin : passer le pilotage au rouge désactivait tout le plugin (et le démon). L'état du pilotage utilise maintenant la clé `pilot_active` ; au premier démarrage le pilotage est actif.
- La question Discord « Compléter avec EDF ? » n'est plus envoyée en mode simulation.
- En simulation, un même ordre n'est journalisé qu'une fois toutes les 5 minutes (au lieu de chaque cycle).

## 0.7.1

- Robustesse : une erreur lors de l'installation ou de la mise à jour d'un équipement, ou dans un cycle du démon, est journalisée (fichier et ligne) au lieu de faire échouer ou désactiver le plugin.

## 0.7.0

- **Réserve de nuit apprise** : le plugin mesure la consommation de la maison de la tombée du jour au lever du soleil, garde les 14 dernières nuits et en déduit le SOC à laisser au soir (SOC mini + besoin de la nuit avec 15 % de marge, sur la capacité de la batterie). Mode **Auto** par défaut ; tant que 3 nuits n'ont pas été mesurées, la valeur par défaut est de 6 kWh.
- **Visible dans le widget** : le texte sous les boutons indique l'origine de la réserve (auto ou manuelle, consommation de nuit apprise, nombre de nuits, nuit en cours). Le bouton **Auto** bascule en manuel ; la jauge n'est utilisable qu'en manuel.
- teslasmartcharge 1.2.0-beta : hausses d'ampérage confirmées en 10 s ; le délai laissé à la Tesla par energyManager passe de 120 s à 60 s.

## 0.6.2

- **Retour à l'état sûr** : au passage de l'interrupteur de pilotage au rouge, les limites que le plugin avait réduites (micro-onduleurs Hoymiles, onduleur Victron, charge de la batterie) reviennent une fois à leur valeur maximale. Rien n'est envoyé en mode simulation.

## 0.6.1

- **Onduleur Victron à 0 W pendant que la Tesla charge** : la batterie maison ne décharge pas pour la Tesla ; la limite onduleur revient à son maximum quand la Tesla ne charge plus (arrêtée ou débranchée), après 30 s. Nécessite l'ordre et le retour « puissance onduleur » dans la configuration Victron. Affichée dans le widget et le simulateur.
- Correctif : plusieurs textes du fichier de tests `tests/engine_test.php` contenaient des apostrophes non échappées (le fichier ne s'exécutait pas).

## 0.6.0

- **Correctif : boucle de la limite Hoymiles** (réduction puis retour au maximum toutes les 10 s, écritures répétées du curseur et alertes en rafale).
- **Agressivité de la Tesla** (équipement Tesla) : Petite 500 W, Moyenne 1000 W, Grande 2000 W. Tant que la Tesla ne charge pas, seuls 500 W sont libérés sur la limite des micro-onduleurs pour déclencher la charge ; une fois en charge, la limite monte par paliers de l'agressivité à mesure que la Tesla consomme ce qui a été libéré. Au-delà du budget d'injection, on réduit seulement jusqu'au budget (jamais jusqu'à zéro tant que la Tesla peut absorber).
- Une hausse manuelle de la charge de la Tesla remet la limite au maximum immédiatement.
- **Marge EDF de teslasmartcharge** : lue automatiquement (`marge_actuelle`). Une marge négative autorise un soutirage EDF que energyManager accepte sans réagir.
- Alertes Discord antirebond : une alerte n'est réarmée qu'après 120 s sans réapparaître ; pas d'alerte « injection critique » pendant que la Tesla monte dans son budget.
- Simulateur : agressivité et marge de la Tesla réglables.

## 0.5.0

- **Tesla branchée = mode Solaire automatique** : energyManager lit si la voiture est branchée (`charge_port_latch` du plugin Tesla, détecté automatiquement depuis teslasmartcharge) et passe teslasmartcharge de Stand-By à Solaire, une seule fois par branchement. Il ne touche jamais aux modes « Non actif » et « Planifié ». Commande « Stand-by » retirée de l'équipement Tesla (Stand-By = voiture débranchée).
- **Ballon rechauffé si l'eau redescend** : un ballon « terminé » (thermostat atteint) mais dont l'eau est repassée sous le minimum (50 °C par défaut) est de nouveau chauffé, au soleil ou en heure creuse d'après-midi, avant l'heure limite. À l'échéance, s'il est froid : OFF et alerte Discord.
- Température minimale du ballon : 50 °C par défaut (réglable sur l'équipement).
- Simulateur : la Tesla se règle en Branchée / Débranchée / Non actif ; on voit energyManager la passer en Solaire.

## 0.4.2

- **La Tesla n'est jamais bridée** : tant qu'elle est en mode Solaire avec de la place (plage de 2 A / 460 W à 32 A / ~7 kW), la limite Hoymiles reste au maximum, même avec une forte injection. Exception : injection critique qui dure plus de 5 minutes malgré tout (Tesla bloquée) ou maximum légal atteint.
- Priorité Tesla : la batterie n'est mise à 0 W de charge qu'une fois la voiture réellement en charge (≥ 460 W). Avant, teslasmartcharge compte la charge de la batterie dans son surplus et démarre normalement ; plus d'injection perdue pendant la montée en puissance.
- Puissance maximale de la Tesla : 7200 W par défaut.
- Simulateur : la Tesla reproduit teslasmartcharge (surplus = batterie - réseau + voiture, paliers de 230 W, confirmation 60 s, coupure immédiate si la maison se vide, seuil batterie en priorité « maison »).

## 0.4.1

- Correctif : la page de configuration du plugin s'affichait vide (erreur de syntaxe PHP introduite en 0.4.0).

## 0.4.0

- **Réserve de nuit** : une jauge (widget et simulateur) indique le SOC que la batterie doit encore avoir au soir, quand la production passe sous le talon de la maison. Le ballon ne passe avant la batterie que si le SOC projeté au soir reste au-dessus (avec la prévision solaire restante si elle est configurée, sinon avec le SOC actuel). Sinon il n'utilise que l'injection réelle ou l'heure creuse EDF, et il est coupé si la batterie se décharge pour lui.
- **Échéance du ballon** : s'il n'a pas atteint la température de son thermostat à l'heure limite (16 h par défaut), il est mis sur OFF et une alerte Discord est envoyée une seule fois par jour. La réponse « Compléter avec EDF » reste possible ensuite.
- **Mention Discord** : nouveau champ « Votre identifiant Discord » ; les alertes critiques vous mentionnent.
- **Limite Hoymiles** : elle reste au maximum tant que quelque chose peut absorber la production (Tesla en mode solaire, consommateur qui démarre ou démarrerait, batterie qui peut charger, ballon sous 50 °C). Elle n'est réduite que s'il n'y a plus rien à alimenter, et revient **immédiatement** au maximum dès qu'une charge arrive.
- Simulateur : jauges « réserve de nuit » et « prévision solaire restante », indication du SOC projeté.

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
