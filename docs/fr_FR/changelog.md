# Changelog

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
