# Energy Manager — conception

Plugin Jeedom (4.6.2, PHP 8.2, français uniquement). Objectif : **ne jamais prélever sur EDF quand le soleil ou la batterie peuvent fournir, ne jamais injecter**, et automatiser les consommateurs pour le confort.

## 1. Installation (d'après le schéma de câblage rév. T et le plan PV du 06/11/2026)

| Élément | Détail | Conséquence pour le plugin |
|---|---|---|
| Victron MultiPlus-II 48/5000 | AC-in via T2-Q1 (C32, **32 A max**), AC-out 1 → tableau TP « secouru ». Pilotage ESS par Jeedom via Modbus TCP (Cerbo GX). Pas de changement de mode ESS. | Leviers : limite de charge (0–3680 W, pas de 5 A), limite onduleur (0–4000 W, pas de 50 W), SOC mini (5–100 %). |
| Mesure réseau | 2 pinces sur la phase de W0 : Shelly EM (TP-Q33) et capteur de courant du MultiPlus | La puissance réseau est mesurée en tête : **elle voit aussi la production Hoymiles côté T2**. |
| Hoymiles « bus secouru » | 2 × HMS-2000 (Quadro, Quadro 2) = 4 000 VA, derrière le MultiPlus (TP-Q31) | Leur production passe par l'AC-out du MultiPlus. |
| Hoymiles « bus réseau » | HMS-1000 « 1000 », 2 × HM-800, HMS-1000 « BiFacial » (garage) = 3 600 VA, sur T2 (non secouru) | Leur surplus ne passe **pas** par l'onduleur du MultiPlus : il part sur le réseau s'il n'est pas consommé. D'où l'importance des consommateurs en T2. |
| Consommateurs pilotés | Tous sur T2 (non secouru) : chauffage entrée/bureau, radiateurs étage, chauffe-eau (T2-Q14), clim (T2-Q31), Tesla P17 (T2-Q41) | Hors réseau EDF, aucun n'est alimenté : le délestage n'a pas à gérer l'îlotage. |
| Batterie | 16 × EVE LFP 314 Ah, 51,2 V, 16 kWh, BMS → Cerbo (CAN) | |
| Abonnement | 9 kVA | Délestage sur soutirage prolongé en place ; délestage selon la somme des puissances (9 kVA) pas encore fait. |

> Point à confirmer : le plan PV indique 14 panneaux sur le toit (6 880 Wc) et 2 au garage (860 Wc, bifaciaux), alors que les échanges évoquaient 6 600 Wc + 1 000 Wc. Les prévisions Solcast devront utiliser les puissances réelles et l'azimut de chaque pan.

## 2. Principes de décision

1. **Données** : si une mesure est périmée (> `stale_age`) ou incohérente, **aucun pilotage** et alerte Discord en boucle.
2. **Batterie d'abord** : le MultiPlus (ESS) charge la batterie avec le surplus. L'injection mesurée (puissance réseau négative) n'apparaît donc que lorsque la limite de charge est atteinte ou la batterie pleine. Le plugin n'intervient que sur cette injection.
3. **Cible de charge** : 100 % ou 95 % (sélecteur du widget). À la cible, la charge est coupée (0 W). Sur 95 %, la marge restante sert de **tampon** : si une injection critique apparaît, la charge est rouverte immédiatement le temps que les consommateurs démarrent.
4. **Tesla** (via teslasmartcharge, qui régule seul : surplus = puissance batterie (charge +) − réseau (soutirage +) + puissance déjà prise par la voiture, paliers de 230 W, de 2 A / 460 W à 32 A / ~7 kW, hausse confirmée 10 s, baisse 60 s, coupure immédiate si la batterie se décharge). energyManager envoie seulement la priorité (`set_priorite_charge` : voiture / maison), le seuil de batterie (`set_seuil_batterie_maison`) et lit `mode_actuel`. **Il ne bride jamais la Tesla en dessous de ce qu'elle peut absorber** : l'injection tolérée sur la limite Hoymiles est limitée au budget de la Tesla (agressivité de l'équipement : 500 / 1000 / 2000 W, 500 W avant le début de la charge) ; sans Tesla active (débranchée, Stand-By, pleine, bloquée) la limite est ramenée à zéro injection. On allume d'abord, puis on libère. Elle revient au maximum dès un soutirage EDF. Priorité Tesla : batterie à 0 W de charge dès que la Tesla est en mode solaire et a de la place ; les autres consommateurs (hors ballon) attendent qu'elle monte (1 min au plus, injection < 1 500 W).
4a. **Réserve de nuit apprise** : consommation de la maison (hors équipements pilotés) intégrée de la tombée du jour (production < 300 W après 17 h) au lever du soleil ; historique des 14 dernières nuits (texte `5.2;4.8;...`), besoin = percentile 75 (6 kWh par défaut avant 3 nuits), réserve = SOC mini + besoin × 1,15 / capacité. Mode Auto par défaut, manuel avec la jauge du widget ; l'origine est affichée dans le widget.
4b. **Pas de décharge de la batterie pour la Tesla** : pendant qu'elle charge (≥ 460 W), la limite onduleur Victron passe à 0 W ; retour au maximum 30 s après l'arrêt ou le débranchement. teslasmartcharge ne vit déjà que du solaire : son surplus = batterie − réseau + voiture = production − maison, sans la batterie (une marge négative tolère un peu d'EDF).
5. **Consommateurs** (un démarrage par cycle, 30 s de stabilisation) : ordre ballon → pièces par priorité 1 à 3. Résistances : pas d'hystérésis ni de durée mini.
6. **Délestage** : soutirage ≥ 150 W pendant 30 s, ou plus de soleil → coupe le moins prioritaire alimenté en solaire.
7. **Hoymiles : jamais d'injection au-delà de ce que la Tesla peut absorber.** Injection autorisée = agressivité de l'équipement Tesla (500 / 1000 / 2000 W) tant qu'elle est active et a de la place (500 W seulement avant qu'elle charge) ; sinon **aucune**. La limite est réduite pour ramener l'injection à ce budget, puis libérée à mesure que la consommation suit : paliers de l'agressivité pour la Tesla, puissance exacte d'un consommateur qu'on vient d'allumer, puissance que la batterie peut encore absorber. Soutirage EDF ou réglage manuel de la Tesla à la hausse : limite remise au maximum. Écritures espacées (flash) sauf injection ≥ 500 W.
8. **Ballon, action prioritaire de la journée (sous réserve de la réserve de nuit : SOC projeté au soir ≥ jauge, sinon injection réelle ou HC EDF seulement ; échéance 16 h : OFF + alerte Discord)** : il passe **avant la batterie et avant la Tesla**, quel que soit le SOC. Il démarre dès que le surplus mobilisable (injection + puissance qui part dans la batterie + puissance prise par la Tesla) atteint 70 % de sa puissance ; la batterie et teslasmartcharge s'ajustent ensuite. Minimum 50 °C par défaut (réglable), pas de maximum (thermostat interne). Chauffe solaire en journée ; chauffe EDF en heure creuse de l'après-midi (14h20–16h20) uniquement si soleil insuffisant ; quand la consommation retombe à 0 après avoir tourné, le ballon est « terminé » pour la journée (et coupé, option). Si le ballon est encore froid à la fin de l'HC : demande Discord (ask) pour compléter avec EDF hors HC — jamais avec la batterie.
9. **Chauffage / clim solaire** : on profite du soleil en journée pour économiser le soir, quitte à dépasser la consigne (« boost »). Les thermostats existants gèrent le soir. Saison automatique selon la moyenne 24 h des 2 sondes extérieures (chauffage < 15 °C, froid > 24 °C).
10. **Batterie jamais chargée par EDF.**

## 3. Architecture

```
plugin_info/   info.json, install.php, configuration.php, packages.json, icône
3rdparty/      sélecteur d'heure (jquery-clock-timepicker) des plages d'heures creuses
core/class/    energyManager.class.php        eqLogic + cmd + démon + E/S Jeedom
               energyManagerEngine.class.php  moteur PUR (aucune E/S), testé et simulable
core/ajax/     simulateur (moteur sur valeurs saisies)
core/template/ widget powerFlow (dashboard + mobile)
desktop/       page des équipements, simulateur, JS, CSS
docs/          documentation utilisateur (fr_FR) et ce document
tests/         tests du moteur (php tests/engine_test.php)
```

Équipements : `manager` (un seul, porte le widget et les sélecteurs), `simple` (radiateur), `ecs`, `tesla`, `clim` (réservé).
Démon : cron Jeedom en mode démon, cadence 10/20/30/60 s (sélecteur).

## 4. Sécurité

- **Mode simulation activé à l'installation** : tout est calculé et journalisé, rien n'est envoyé.
- **Configuration illisible = simulation forcée** : si la lecture de la configuration échoue, le cycle ne s'exécute qu'en simulation (aucun ordre) et une alerte est envoyée.
- Les lignes `[SIMULATION]` sont journalisées au niveau Info (niveau Debug pour les ordres réels) : régler le niveau de log du plugin en conséquence.
- Chaque ordre Victron / Ahoy est vérifié par son **retour d'information** (alerte si non confirmé). **Configurer les retours de limite est fortement recommandé** : sans eux, la limite « actuelle » n'est connue que par la dernière consigne envoyée.
- La cadence du démon n'est lue qu'au démarrage : le redémarrer après un changement.
- Alertes Discord (webhook) en boucle : donnée périmée/incohérente, injection ≥ 500 W prolongée, injection ≥ 3 000 W, ordre sans retour.

## 5. Feuille de route

Livré (voir le changelog) :

- **v0.1 à v0.3** : structure, configuration, équipements simple/ballon/Tesla, moteur, widget, alertes, plages d'heures creuses multiples, intégration de teslasmartcharge, page de simulation complète.
- **v0.4 à v0.7** : réserve de nuit (apprise sur les 14 dernières nuits, mode Auto ou manuel), échéance du ballon, mention Discord, onduleur Victron à 0 W pendant la charge Tesla, retour à l'état sûr, robustesse des cycles.
- **v0.8 à v0.10** : extinction propre d'un équipement que l'on cesse de piloter, priorités Tesla / Batterie revues avec seuil de SOC réglable, jamais d'injection au-delà de l'agressivité de la Tesla (zéro sans Tesla active).

À venir :

- Délestage selon la somme des puissances (abonnement 9 kVA), planning de présence et confort/éco par pièce, ouvertures, widget : barre HC/HP, statistiques.
- Prévisions Solcast + MeteoFull (fusion pondérée), coûts HC/HP, panel.
- Climatisation tri-split (3 chambres, ESP32 CN105).
- **v1.0** : documentation complète, tests sur Jeedom réel, soumission au market.

## 6. Points ouverts

- Climatisation tri-split : mise en attente à la demande de l'utilisateur.
- Prévisions (Solcast / MeteoFull) : à reprendre plus tard.
- Plugin teslasmartcharge : dépôt https://github.com/PaTiTan/plugin-teslasmartcharge ; energyManager pilote `set_mode` et `set_priorite_charge` et lit `mode_actuel`. Sondes de teslasmartcharge : habitation = Puissance live EDF (soutirage positif), batterie = SOC et puissance, voiture = puissance live Tesla.
- Format exact de la réponse « ask » du plugin Discord : à vérifier sur le Jeedom réel (un bouton « Compléter avec EDF » existe en secours).
- Le code n'a pas pu être exécuté ni vérifié syntaxiquement (pas de PHP sur le poste de développement) : lancer `php -l` et `php tests/engine_test.php` avant tout déploiement.
