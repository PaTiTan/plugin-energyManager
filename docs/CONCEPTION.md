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
| Abonnement | 9 kVA | Délestage de sécurité prévu en v0.2 (somme des puissances). |

> Point à confirmer : le plan PV indique 14 panneaux sur le toit (6 880 Wc) et 2 au garage (860 Wc, bifaciaux), alors que les échanges évoquaient 6 600 Wc + 1 000 Wc. Les prévisions Solcast devront utiliser les puissances réelles et l'azimut de chaque pan.

## 2. Principes de décision

1. **Données** : si une mesure est périmée (> `stale_age`) ou incohérente, **aucun pilotage** et alerte Discord en boucle.
2. **Batterie d'abord** : le MultiPlus (ESS) charge la batterie avec le surplus. L'injection mesurée (puissance réseau négative) n'apparaît donc que lorsque la limite de charge est atteinte ou la batterie pleine. Le plugin n'intervient que sur cette injection.
3. **Cible de charge** : 100 % ou 95 % (sélecteur du widget). À la cible, la charge est coupée (0 W). Sur 95 %, la marge restante sert de **tampon** : si une injection critique apparaît, la charge est rouverte immédiatement le temps que les consommateurs démarrent.
4. **Tesla** (sélecteur Tesla / Batterie, envoyé à teslasmartcharge via `set_priorite_charge` : voiture / maison) : en mode solaire et priorité Tesla, charge batterie à **0 W** ; teslasmartcharge régule seul (±300 W tolérés). Il compte la charge batterie, l'injection et sa propre puissance comme surplus (soutirage positif, batterie positive en charge). Il confirme chaque changement 60 s : energyManager **laisse la Tesla monter** (2 min au plus, injection < 1 500 W) avant de donner le surplus à un autre consommateur ou de brider les Hoymiles. Le mode est lu dans `mode_actuel`.
5. **Consommateurs** (un démarrage par cycle, 30 s de stabilisation) : ordre ballon → pièces par priorité 1 à 3. Résistances : pas d'hystérésis ni de durée mini.
6. **Délestage** : soutirage ≥ 150 W pendant 30 s, ou plus de soleil → coupe le moins prioritaire alimenté en solaire.
7. **Hoymiles = dernier recours, toujours au maximum** : limitation uniquement si injection ≥ 500 W et que rien ne peut absorber (Tesla solaire avec de la place, consommateur qui démarre ou démarrerait, ballon < 50 °C) ; retour **immédiat** au maximum dès qu'une charge arrive ; remise au maximum la nuit. Le curseur est en flash : écritures espacées (60 s, 50 W mini), sauf réduction critique.
8. **Ballon, action prioritaire de la journée (sous réserve de la réserve de nuit : SOC projeté au soir ≥ jauge, sinon injection réelle ou HC EDF seulement ; échéance 16 h : OFF + alerte Discord)** : il passe **avant la batterie et avant la Tesla**, quel que soit le SOC. Il démarre dès que le surplus mobilisable (injection + puissance qui part dans la batterie + puissance prise par la Tesla) atteint 70 % de sa puissance ; la batterie et teslasmartcharge s'ajustent ensuite. Minimum 52 °C, pas de maximum (thermostat interne). Chauffe solaire en journée ; chauffe EDF en heure creuse de l'après-midi (14h20–16h20) uniquement si soleil insuffisant ; quand la consommation retombe à 0 après avoir tourné, le ballon est « terminé » pour la journée (et coupé, option). Si le ballon est encore froid à la fin de l'HC : demande Discord (ask) pour compléter avec EDF hors HC — jamais avec la batterie.
9. **Chauffage / clim solaire** : on profite du soleil en journée pour économiser le soir, quitte à dépasser la consigne (« boost »). Les thermostats existants gèrent le soir. Saison automatique selon la moyenne 24 h des 2 sondes extérieures (chauffage < 15 °C, froid > 24 °C).
10. **Batterie jamais chargée par EDF.**

## 3. Architecture

```
plugin_info/   info.json, install.php, configuration.php, packages.json, icône
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
- Chaque ordre Victron / Ahoy est vérifié par son **retour d'information** (alerte si non confirmé).
- Alertes Discord (webhook) en boucle : donnée périmée/incohérente, injection ≥ 500 W prolongée, injection ≥ 3 000 W, ordre sans retour.

## 5. Feuille de route

- **v0.1** (ce dépôt) : structure, configuration, équipements simple/ballon/Tesla, moteur, simulateur, widget, alertes.
- **v0.2** : délestage 9 kVA, planning de présence et confort/éco par pièce, ouvertures, widget : 16 panneaux et barre HC/HP, statistiques.
- **v0.3** : prévisions Solcast + MeteoFull (fusion pondérée), apprentissage de la consommation nocturne → SOC mini dynamique, coûts HC/HP, panel.
- **v0.4** : climatisation tri-split (3 chambres, ESP32 CN105).
- **v1.0** : documentation complète, tests sur Jeedom réel, soumission au market.

## 6. Points ouverts

- Climatisation tri-split : mise en attente à la demande de l'utilisateur.
- Prévisions (Solcast / MeteoFull) : à reprendre plus tard.
- Plugin teslasmartcharge : dépôt https://github.com/PaTiTan/plugin-teslasmartcharge ; energyManager pilote `set_mode` et `set_priorite_charge` et lit `mode_actuel`. Sondes de teslasmartcharge : habitation = Puissance live EDF (soutirage positif), batterie = SOC et puissance, voiture = puissance live Tesla.
- Format exact de la réponse « ask » du plugin Discord : à vérifier sur le Jeedom réel (un bouton « Compléter avec EDF » existe en secours).
- Le code n'a pas pu être exécuté ni vérifié syntaxiquement (pas de PHP sur le poste de développement) : lancer `php -l` et `php tests/engine_test.php` avant tout déploiement.
