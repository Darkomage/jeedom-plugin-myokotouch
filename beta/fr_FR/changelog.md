# Changelog — MyOkoTouch

> **IMPORTANT**
>
> S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

> Historique des versions du plugin MyOkoTouch.

## 1.1.3-beta — 2026-09-23

### Corrections
- **Arrêt du démon** : l'arrêt du démon ne tue plus les démons d'autres plugins Jeedom construits sur le même template (le motif `pkill` est désormais restreint au chemin `myokotouch/resources/demond/demond.py`).
- **Arrêt du démon sous PHP 8 (Debian 12)** : correction de l'erreur « Undefined constant SIGTERM » qui interrompait l'arrêt du démon lorsqu'il est déclenché depuis l'interface (ex. réinstallation des dépendances).
- **Logs du démon** : les erreurs de lecture/écriture affichent désormais le type de l'exception (auparavant `[poll] erreur:` pouvait apparaître sans aucun détail).

## 0.0.2-beta — 2026-05-20

Mise à jour des liens de documentation.

## 0.0.1-beta — 2026-04-13

Première version beta publique.

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
