# Changelog — MyOkoTouch

> **IMPORTANT**
>
> S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

> Historique des versions du plugin MyOkoTouch.

## 1.2.0-beta — 2026-09-25

Version beta alignée sur la stable 1.2.0 (contenu des 1.1.3-beta et 1.1.4-beta ci-dessous).

## 1.1.4-beta — 2026-09-24

### Nouveautés
- **Types génériques** : les principales commandes reçoivent un type générique Jeedom (circuit de chauffage présenté comme thermostat, températures, état de chauffe, modes, consommation de pellets, météo) pour l'application mobile et les passerelles (Homebridge, Google Home, Alexa…). Un type modifié manuellement n'est pas écrasé ; lancer une synchronisation pour l'appliquer aux équipements existants.

### Corrections
- **Sécurité des logs** : la clé API Jeedom et le mot de passe Okofen n'apparaissent plus en clair dans les logs (ligne de lancement du démon, URL de callback journalisée en mode Debug, messages socket).

## 1.1.3-beta — 2026-09-23

### Corrections
- **Arrêt du démon** : l'arrêt du démon ne tue plus les démons d'autres plugins Jeedom construits sur le même template (le motif `pkill` est désormais restreint au chemin `myokotouch/resources/demond/demond.py`).
- **Arrêt du démon sous PHP 8 (Debian 12)** : correction de l'erreur « Undefined constant SIGTERM » qui interrompait l'arrêt du démon lorsqu'il est déclenché depuis l'interface (ex. réinstallation des dépendances).
- **Logs du démon** : les erreurs de lecture/écriture affichent désormais le type de l'exception (auparavant `[poll] erreur:` pouvait apparaître sans aucun détail).

## 0.0.2-beta — 2026-05-20

Mise à jour des liens de documentation.

## 0.0.1-beta — 2026-04-13

Première version beta publique.

## 1.2.0 — 2026-09-25

### Nouveautés
- **Types génériques** : les principales commandes reçoivent un type générique Jeedom (circuit de chauffage présenté comme thermostat, températures, état de chauffe, modes, consommation de pellets, météo) pour l'application mobile et les passerelles (Homebridge, Google Home, Alexa…). Un type modifié manuellement n'est pas écrasé ; lancer une synchronisation pour l'appliquer aux équipements existants.

### Corrections
- **Compatibilité Debian 12 / PHP 8** : l'arrêt du démon (bouton Arrêter, réinstallation des dépendances) ne plante plus sur l'erreur « Undefined constant SIGTERM ».
- **Arrêt du démon** : n'arrête plus par erreur les démons d'autres plugins construits sur le même modèle.
- **Sécurité des logs** : la clé API Jeedom et le mot de passe Okofen n'apparaissent plus en clair dans les logs.
- **Logs du démon** : les erreurs de communication avec la chaudière indiquent désormais leur nature (auparavant `[poll] erreur:` pouvait apparaître sans détail).

## 1.1.2 — 2026-06-30

### Corrections
- **Synchronisation** : corrige un échec de synchronisation (« Duplicate entry… ») sur les équipements `weather` en mode expert — la variable Okofen `refresh` n'entre plus en conflit avec la commande « Rafraîchir » de Jeedom. Une commande parasite éventuellement créée par la 1.1.1 est nettoyée automatiquement.

## 1.1.1 — 2026-06-30

### Nouveautés
- **Bouton de diagnostic** dans la configuration : génère un fichier à envoyer au support (retour brut de la chaudière + informations d'encodage), mot de passe exclu.
- **Synchronisation via le démon** : « Synchroniser » respecte le délai imposé par la chaudière et évite les erreurs de lecture concurrente.
- **Jauges et graphes** : bornes réalistes sur les mesures (températures extérieure / ambiante / eau, pourcentages…).

### Corrections
- **Caractères spéciaux des unités** : `°C` ne s'affiche plus `?C` (encodage UTF-8 fiabilisé de bout en bout ; substitution `?C → °C` pour les firmwares mutilant le caractère « ° »).
- **Affichage de la version** du plugin : ne reste plus figé sur une ancienne valeur.
- **Typage des commandes** : les valeurs non numériques (heures, prévisions météo) sont traitées comme du texte ; les réglages sans bornes réelles passent en saisie libre au lieu d'un curseur incohérent.
- **Installation des dépendances Python** fiabilisée — corrige le démarrage du démon sur une installation neuve.

## 1.0.0 — 2026-05-22

Première release stable.
