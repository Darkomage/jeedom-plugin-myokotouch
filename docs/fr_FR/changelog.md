# Changelog — MyOkoTouch

> **IMPORTANT**
>
> S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

> Historique des versions du plugin MyOkoTouch.

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
