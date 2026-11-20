# Plugin Energy Manager

## Présentation

Energy Manager pilote l'autoconsommation de votre installation : onduleur-chargeur Victron MultiPlus-II, micro-onduleurs Hoymiles (via AhoyDTU), ballon d'eau chaude, radiateurs et recharge Tesla (plugin teslasmartcharge). Il décide, toutes les 10 à 60 secondes, quels consommateurs allumer pour ne rien injecter sur le réseau et ne rien gaspiller.

> **Le plugin démarre en mode simulation** : il calcule et journalise ses décisions sans rien commander. Désactivez la simulation seulement après avoir vérifié le journal.

## Configuration du plugin

Menu **Plugins → Énergie → Energy Manager → Configuration**.

- **Sécurité et fonctionnement** : mode simulation, cadence de décision, âge maximal d'une mesure (le SOC batterie n'est pas contrôlé en âge par défaut).
- **Alertes** : webhook Discord d'urgence (alertes répétées en boucle), commande de message avec « ask » du plugin Discord.
- **Mesures** : puissance réseau (positive = soutirage EDF, négative = injection), production PV, batterie (puissance et SOC), maison, compteurs du jour.
- **Victron** : pour chaque levier (charge batterie, onduleur, SOC mini) une commande d'**ordre** et une commande de **retour**. Un ordre non confirmé par son retour déclenche une alerte.
- **Hoymiles** : curseur de limitation (ordre et retour). C'est un dernier recours.
- **Températures** : deux sondes extérieures, présence des parents.
- **Tarification** : plages d'heures creuses ajoutées avec le bouton **Ajouter une plage** et un sélecteur d'heure. Le reste du temps est en heures pleines. teslasmartcharge peut reprendre ces plages.
- **Prévisions** : commande optionnelle de prévision PV restante.

## Équipements

Chaque consommateur géré est un équipement du plugin.

| Type | Rôle |
|---|---|
| Consommateur simple | Radiateur piloté par un Shelly (ON/OFF), pièce avec sonde, consigne, priorité 1 à 3, capteur d'ouverture |
| Ballon d'eau chaude | Action prioritaire de la journée : passe avant la batterie et la Tesla dès que le surplus mobilisable (injection + charge batterie + Tesla) atteint 70 % de sa puissance. Il ne passe avant la batterie que si elle gardera la **réserve de nuit** réglée par la jauge ; sinon : injection réelle ou heure creuse. En heure creuse d'après-midi, si le soleil ne suffit pas, EDF le chauffe jusqu'à ce que son thermostat coupe. Non chaud à l'heure limite (16 h) : OFF + alerte Discord. Minimum 52 °C. |
| Tesla | Passe teslasmartcharge en mode Solaire dès que la voiture est branchée (une fois par branchement, détection automatique via le plugin Tesla), et envoie la priorité (voiture ou maison). Stand-By = voiture débranchée, géré par teslasmartcharge. |
| Gestionnaire | Un seul : porte le widget powerFlow et les sélecteurs |

Chaque équipement a trois modes : **auto**, **forcé ON**, **inactif**.

## Widget

Le widget du gestionnaire reprend le principe du powerFlow : solaire, réseau, MultiPlus, maison, batterie, consommateurs, avec flux animés. Sélecteurs : priorité Tesla ou batterie, cible de charge 95 % ou 100 %, simulation. Un **interrupteur rouge/vert** en haut active ou coupe tout le pilotage, et chaque consommateur a son propre voyant (vert = piloté par le plugin, rouge = ignoré).

## Simulateur

Deux outils distincts :

- **Mode simulation** (configuration du plugin, bouton du widget) : le plugin lit les vraies mesures et calcule ses décisions, mais n'envoie aucun ordre.
- **Page Simulateur** (bouton **Simulateur** de la page des équipements) : une installation entièrement virtuelle. Le widget est animé, des jauges permettent de régler le solaire disponible, le talon de la maison, le SOC, la température du ballon et des pièces, l'heure et la vitesse. Le vrai moteur de décision est exécuté à chaque cycle avec votre configuration (équipements, priorités, plages d'heures creuses) et ses ordres, décisions et alertes sont affichés en direct. Rien n'est lu ni commandé sur l'installation. Le modèle de la batterie, du ballon, des pièces et de teslasmartcharge est simplifié.

## FAQ

**Pourquoi la batterie s'arrête à 95 % ?** C'est le choix « 95 % » : la marge sert de tampon pour encaisser un surplus soudain le temps que les consommateurs démarrent.

**Pourquoi le curseur Hoymiles reste au maximum ?** Parce qu'il n'est utilisé qu'en dernier recours, quand aucun consommateur ne peut absorber l'injection.

**Le ballon n'a pas chauffé.** Si l'heure creuse d'après-midi est passée et qu'il est resté froid, une demande vous est envoyée sur Discord pour compléter avec EDF.

## Avec teslasmartcharge

energyManager est le seul décideur de la priorité : le sélecteur « Tesla / Batterie » du widget envoie `voiture` ou `maison` à teslasmartcharge. Le mode est lu dans `mode_actuel` (teslasmartcharge bascule seul en Stand-By si la voiture est débranchée ou la cible atteinte).

Avec la priorité Tesla, la limite de charge de la batterie passe à 0 W et le surplus part vers la voiture. Comme teslasmartcharge confirme chaque changement pendant 60 s, energyManager laisse la voiture monter (2 minutes au plus, tant que l'injection reste sous 1500 W) avant de donner le surplus à un autre consommateur ou de brider les Hoymiles.
