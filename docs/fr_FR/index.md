# Plugin Energy Manager

## Présentation

Energy Manager pilote l'autoconsommation de votre installation : onduleur-chargeur Victron MultiPlus-II, micro-onduleurs Hoymiles (via AhoyDTU), ballon d'eau chaude, radiateurs et recharge Tesla (plugin teslasmartcharge). Il décide, toutes les 10 à 60 secondes, quels consommateurs allumer pour ne rien injecter sur le réseau et ne rien gaspiller.

> **Le plugin démarre en mode simulation** : il calcule et journalise ses décisions sans rien commander. Désactivez la simulation seulement après avoir vérifié le journal.

## Configuration du plugin

Menu **Plugins → Énergie → Energy Manager → Configuration**.

- **Sécurité et fonctionnement** : mode simulation, cadence de décision, âge maximal d'une mesure.
- **Alertes** : webhook Discord d'urgence (alertes répétées en boucle), commande de message avec « ask » du plugin Discord.
- **Mesures** : puissance réseau (négative = injection), production PV, batterie (puissance et SOC), maison, compteurs du jour.
- **Victron** : pour chaque levier (charge batterie, onduleur, SOC mini) une commande d'**ordre** et une commande de **retour**. Un ordre non confirmé par son retour déclenche une alerte.
- **Hoymiles** : curseur de limitation (ordre et retour). C'est un dernier recours.
- **Températures** : deux sondes extérieures, présence des parents.
- **Tarification** : créneaux HC/HP, une ligne par créneau, par exemple `14:20-16:20 HC`.
- **Prévisions** : commande optionnelle de prévision PV restante.

## Équipements

Chaque consommateur géré est un équipement du plugin.

| Type | Rôle |
|---|---|
| Consommateur simple | Radiateur piloté par un Shelly (ON/OFF), pièce avec sonde, consigne, priorité 1 à 3, capteur d'ouverture |
| Ballon d'eau chaude | Minimum 52 °C, thermostat interne, chauffe solaire ou en heure creuse d'après-midi |
| Tesla | Modes solaire / stand-by / non actif, relayés vers teslasmartcharge |
| Gestionnaire | Un seul : porte le widget powerFlow et les sélecteurs |

Chaque équipement a trois modes : **auto**, **forcé ON**, **inactif**.

## Widget

Le widget du gestionnaire reprend le principe du powerFlow : solaire, réseau, MultiPlus, maison, batterie, consommateurs, avec flux animés. Sélecteurs : priorité Tesla ou batterie, cible de charge 95 % ou 100 %, simulation.

## Simulateur

Dans la page des équipements, le bouton **Simulateur** exécute le moteur sur des valeurs que vous saisissez et affiche les décisions, sans envoyer aucun ordre.

## FAQ

**Pourquoi la batterie s'arrête à 95 % ?** C'est le choix « 95 % » : la marge sert de tampon pour encaisser un surplus soudain le temps que les consommateurs démarrent.

**Pourquoi le curseur Hoymiles reste au maximum ?** Parce qu'il n'est utilisé qu'en dernier recours, quand aucun consommateur ne peut absorber l'injection.

**Le ballon n'a pas chauffé.** Si l'heure creuse d'après-midi est passée et qu'il est resté froid, une demande vous est envoyée sur Discord pour compléter avec EDF.
