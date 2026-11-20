# Installer et tester Energy Manager

Ce tutoriel installe le plugin depuis GitHub, vérifie le code, puis le configure **en mode simulation**. Rien n'est commandé tant que vous ne désactivez pas la simulation.

## 1. Installer le plugin depuis GitHub

1. **Réglages → Système → Configuration → Mises à jour/Market → onglet Github** : cochez **Activer Github**. Le token est inutile pour un dépôt public.
2. **Plugins → Gestion des plugins**, puis l'ajout d'un plugin depuis une source (bouton Market ou « Ajouter un plugin » selon la version).
3. Source **Github** :
   - ID logique du plugin : `energyManager`
   - Utilisateur ou organisation : `PaTiTan`
   - Nom du dépôt : `plugin-energyManager`
   - Branche : `main`
4. Validez : le plugin apparaît dans la liste. Ouvrez-le et **activez-le**.
5. À l'activation, l'équipement **Gestionnaire d'énergie** est créé automatiquement.

## 2. Vérifier le code (en SSH, une seule fois)

```bash
cd /var/www/html/plugins/energyManager
find . -name "*.php" -exec php -l {} \;
php tests/engine_test.php
```

Attendu : « No syntax errors detected » pour chaque fichier, puis `Tous les tests passent.`
Si une ligne est en erreur, envoyez-la : c'est le premier contrôle du code.

## 3. Configurer le plugin

**Plugins → Énergie → Energy Manager → icône Configuration.** Laissez **Mode simulation** coché.

1. **Mesures** : puissance réseau (**positive = soutirage EDF**, négative = injection), production PV, puissance et SOC batterie, puissance maison. Utilisez le bouton à droite de chaque champ pour choisir la commande.
2. **Victron** : pour la charge batterie et l'onduleur, une commande d'**ordre** et une commande de **retour**. Configurez bien les retours : sans eux, le plugin ne peut pas vérifier que les ordres sont appliqués. (Les champs « SOC mini » sont réservés pour une version future.)
3. **Hoymiles** : le curseur de limite (ordre **et retour**, fortement recommandé), maximum 7600 W.
4. **Températures** : les 2 sondes extérieures (la présence des parents est réservée pour une version future).
5. **Tarification** : cliquez sur **Ajouter une plage** pour chaque période d'heures creuses, puis réglez le début et la fin avec le sélecteur d'heure (de 5 en 5 minutes). Pour votre contrat : 00h50-06h50 et 14h20-16h20. Le reste du temps est en heures pleines.
6. **Alertes** : webhook Discord (`https://discord.com/api/webhooks/...`) et, si vous voulez la question « compléter avec EDF ? », la commande de message du plugin Discord.
7. Sauvegardez.

## 4. Démarrer le démon

Sur la page du plugin, bloc **Démon** : cliquez **Démarrer** et activez la gestion automatique. Choisissez la cadence (10 s à 60 s) dans la configuration ; **après tout changement de cadence, redémarrez le démon** (elle n'est lue qu'au démarrage).

## 5. Créer les équipements

**Ajouter** sur la page du plugin, puis choisissez le type :

- **Consommateur simple (radiateur)** : commandes ON/OFF du Shelly, puissance mesurée, puissance théorique, sonde de la pièce, consigne, priorité 1 à 3, capteur d'ouverture.
- **Ballon d'eau chaude** : ON/OFF, puissance mesurée, température de l'eau, minimum 50 °C par défaut (réglable).
- **Tesla** : les commandes de teslasmartcharge `set_mode` (Définir Mode) et `set_priorite_charge` (Définir Priorité), l'info `mode_actuel` (Mode Actuel) et la puissance de charge. energyManager devient le seul décideur du mode et de la priorité : il lit le mode que teslasmartcharge choisit lui-même (Stand-By si débranchée, cible atteinte).

## 6. Observer en simulation

- Commande **Décision** du Gestionnaire : ce que le plugin ferait à chaque cycle.
- **Analyse → Logs → energyManager** : chaque ordre est préfixé `[SIMULATION]`. Ces lignes sont de niveau **Info** : réglez le niveau de log du plugin sur **Info** (configuration du plugin, section Logs, ou Réglages → Système → Configuration → Logs), sinon vous ne verrez rien (Jeedom n'affiche par défaut que les erreurs). Passez-le sur **Debug** pour voir les ordres réellement envoyés une fois la simulation désactivée.
- Si la configuration du plugin est illisible (erreur de base de données), le cycle est forcé en simulation et une alerte est envoyée : aucun ordre n'est alors émis.
- Bouton **Simulateur** de la page du plugin : une installation virtuelle avec des jauges (soleil, talon, SOC, ballon, pièces), l'heure réglable et le journal des décisions en direct. À essayer avant toute mise en service.
- Placez le widget du Gestionnaire sur le dashboard.

Vérifiez au moins une journée complète : démarrages au bon moment, aucun ordre incohérent, aucune alerte inattendue.

## 7. Passer en réel, progressivement

1. Décochez **Mode simulation** (ou le bouton OFF du widget).
2. Mettez d'abord tous les équipements en mode **Inactif** sauf un radiateur, puis ajoutez le ballon, puis la Tesla.
3. Surveillez la limite Hoymiles : elle est réduite pour que l'injection ne dépasse pas le budget de la Tesla (500 / 1000 / 2000 W selon son agressivité, 500 W avant que la charge commence), et **à zéro injection sans Tesla active** ; on allume d'abord un consommateur, puis on libère sa puissance sur la limite. Elle revient au maximum 7600 W dès qu'un soutirage EDF apparaît.
4. En cas de doute, remettez la simulation sur ON : plus aucun ordre n'est envoyé.

## 8. Mettre à jour

Quand une nouvelle version est publiée, relancez la mise à jour du plugin depuis **Plugins → Gestion des plugins**.
