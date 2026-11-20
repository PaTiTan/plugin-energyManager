# Plugin Energy Manager

## Présentation

Energy Manager pilote l'autoconsommation de votre installation : onduleur-chargeur Victron MultiPlus-II, micro-onduleurs Hoymiles (via AhoyDTU), ballon d'eau chaude, radiateurs et recharge Tesla (plugin teslasmartcharge). Il décide, toutes les 10 à 60 secondes, quels consommateurs allumer pour ne rien injecter sur le réseau et ne rien gaspiller.

> **Le plugin démarre en mode simulation** : il calcule et journalise ses décisions sans rien commander. Désactivez la simulation seulement après avoir vérifié le journal.

## Configuration du plugin

Menu **Plugins → Énergie → Energy Manager → Configuration**.

- **Sécurité et fonctionnement** : mode simulation, cadence de décision (**redémarrez le démon après un changement de cadence** : elle n'est lue qu'au démarrage), âge maximal d'une mesure (le SOC batterie n'est pas contrôlé en âge par défaut), production solaire minimale et soutirage déclenchant le délestage.
  - Si la configuration du plugin ne peut pas être lue (erreur de base de données), le cycle est **forcé en simulation** (aucun ordre n'est envoyé) et une alerte est envoyée.
- **Alertes** : webhook Discord d'urgence (alertes répétées en boucle), commande de message avec « ask » du plugin Discord.
- **Mesures** : puissance réseau (positive = soutirage EDF, négative = injection), production PV, batterie (puissance et SOC), maison ; les compteurs du jour sont réservés pour une version future.
- **Victron** : pour chaque levier (charge batterie, onduleur) une commande d'**ordre** et une commande de **retour**. Un ordre non confirmé par son retour déclenche une alerte. **Configurez absolument les retours** de limite (charge batterie, onduleur) : sans eux, le plugin ne connaît que sa dernière consigne envoyée et ne peut pas vérifier que l'ordre a été appliqué.
- **Hoymiles** : curseur de limitation (ordre et retour). Même recommandation : configurez le retour de limite d'AhoyDTU pour que la limite réellement appliquée soit connue.
- **Températures** : deux sondes extérieures (la présence des parents est réservée pour une version future).
- **Tarification** : plages d'heures creuses ajoutées avec le bouton **Ajouter une plage** et un sélecteur d'heure. Le reste du temps est en heures pleines. teslasmartcharge peut reprendre ces plages. Les prix du kWh sont réservés pour une version future.
- **Prévisions** : commande optionnelle de prévision PV restante.

## Équipements

Chaque consommateur géré est un équipement du plugin.

| Type | Rôle |
|---|---|
| Consommateur simple | Radiateur piloté par un Shelly (ON/OFF), pièce avec sonde, consigne, priorité 1 à 3, capteur d'ouverture |
| Ballon d'eau chaude | Action prioritaire de la journée : passe avant la batterie et la Tesla dès que le surplus mobilisable (injection + charge batterie + Tesla) atteint 70 % de sa puissance. Il ne passe avant la batterie que si elle gardera la **réserve de nuit** réglée par la jauge ; sinon : injection réelle ou heure creuse. En heure creuse d'après-midi, si le soleil ne suffit pas, EDF le chauffe jusqu'à ce que son thermostat coupe. Non chaud à l'heure limite (16 h) : OFF + alerte Discord. Minimum 50 °C par défaut (réglable). |
| Tesla | Passe teslasmartcharge en mode Solaire dès que la voiture est branchée (une fois par branchement, détection automatique via le plugin Tesla), et envoie la priorité (voiture ou maison) et le seuil de batterie. Stand-By = voiture débranchée, géré par teslasmartcharge. Réglage d'**agressivité** : Petite (paliers de 500 W), Moyenne (1000 W) ou Grande (2000 W) = puissance libérée à la fois sur la limite des micro-onduleurs une fois la charge en cours (500 W seulement avant le début de la charge). |
| Gestionnaire | Un seul : porte le widget powerFlow et les sélecteurs |

Les consommateurs simples (radiateurs, ballon) ont trois modes : **auto**, **forcé ON**, **inactif**. La Tesla n'a que deux modes côté energyManager : **Solaire** (piloté) ou **Non actif** (teslasmartcharge ne fait plus rien ; ce bouton n'arrête pas la charge en cours : pour l'arrêter proprement, passez le voyant de pilotage de la Tesla au rouge, ce qui envoie Stand-By puis Non actif).

## Widget

Le widget du gestionnaire reprend le principe du powerFlow : solaire, réseau, MultiPlus, maison, batterie, consommateurs, avec flux animés. Sélecteurs : priorité Tesla ou batterie, cible de charge 95 % ou 100 %, simulation.

- **Curseur de seuil (priorité Batterie)** : avec la priorité Batterie, la batterie se charge à pleine puissance jusqu'à ce SOC, puis la Tesla passe d'abord. Le seuil est aussi envoyé à teslasmartcharge (`set_seuil_batterie_maison`).
- **Réserve de nuit** : une jauge règle le SOC à laisser au soir. Le bouton **Auto** la fait calculer par le plugin d'après la consommation de nuit mesurée (percentile 75 des 14 dernières nuits, 6 kWh tant que 3 nuits n'ont pas été mesurées) ; le bouton bascule entre automatique et manuel, et la jauge n'est utilisable qu'en manuel. Le texte sous les boutons indique l'origine de la valeur.

Un **interrupteur rouge/vert** en haut active ou coupe tout le pilotage, et chaque consommateur a son propre voyant (vert = piloté par le plugin, rouge = ignoré).

## Simulateur

Deux outils distincts :

- **Mode simulation** (configuration du plugin, bouton du widget) : le plugin lit les vraies mesures et calcule ses décisions, mais n'envoie aucun ordre. Pour voir les lignes `[SIMULATION]` dans **Analyse → Logs → energyManager**, réglez le niveau de log du plugin sur **Info** (configuration des logs de Jeedom, par défaut le niveau est « Erreur ») ; réglez-le sur **Debug** pour voir aussi les ordres réellement envoyés.
- **Page Simulateur** (bouton **Simulateur** de la page des équipements) : une installation entièrement virtuelle. Le widget est animé, des jauges permettent de régler le solaire disponible, le talon de la maison, le SOC, la température du ballon et des pièces, l'heure et la vitesse. Le vrai moteur de décision est exécuté à chaque cycle avec votre configuration (équipements, priorités, plages d'heures creuses) et ses ordres, décisions et alertes sont affichés en direct. Rien n'est lu ni commandé sur l'installation. Le modèle de la batterie, du ballon, des pièces et de teslasmartcharge est simplifié.

## FAQ

**Pourquoi la batterie s'arrête à 95 % ?** C'est le choix « 95 % » : la marge sert de tampon pour encaisser un surplus soudain le temps que les consommateurs démarrent.

**Pourquoi le curseur Hoymiles baisse alors que j'ai du soleil ?** Le plugin n'accepte d'injecter que ce que la Tesla peut absorber : son budget est l'agressivité de l'équipement Tesla (500, 1000 ou 2000 W), et seulement 500 W tant que la charge n'a pas commencé. Sans Tesla active (débranchée, Stand-By, pleine, bloquée), aucune injection n'est tolérée. Quand l'injection dépasse ce budget, la limite est réduite pour la ramener au budget. On allume d'abord un consommateur, puis on libère sa puissance (moins l'injection déjà visible) sur la limite, et ainsi de suite. Dès qu'un soutirage EDF significatif apparaît (au-delà de 50 W et de la marge négative éventuelle de teslasmartcharge), la limite revient au maximum.

**Le ballon n'a pas chauffé.** Si l'heure creuse d'après-midi est passée et qu'il est resté froid, une demande vous est envoyée sur Discord pour compléter avec EDF.

## Avec teslasmartcharge

energyManager est le seul décideur de la priorité : le sélecteur « Tesla / Batterie » du widget envoie `voiture` ou `maison` à teslasmartcharge. Le mode est lu dans `mode_actuel` (teslasmartcharge bascule seul en Stand-By si la voiture est débranchée ou la cible atteinte).

Avec la priorité Tesla, la limite de charge de la batterie passe à 0 W et le surplus part vers la voiture. Comme teslasmartcharge confirme une hausse pendant 10 s et une baisse pendant 60 s, energyManager laisse la voiture monter (1 minute au plus, tant que l'injection reste sous 1500 W) avant de donner le surplus à un autre consommateur ou de brider les Hoymiles.

Avec la priorité Batterie, la batterie se charge à pleine puissance jusqu'au seuil de SOC réglé avec le curseur du widget ; au-delà, la Tesla passe d'abord. Ce seuil est envoyé à la commande « Définir Seuil Bat Maison » de teslasmartcharge (détectée automatiquement ; un champ facultatif de l'équipement Tesla permet de la désigner à la main).