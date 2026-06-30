# Changelog — MyOkoTouch

> **IMPORTANT**
>
> S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

> Historique des versions du plugin MyOkoTouch.

## 1.1.0-beta — 2026-06-30

### Nouveautés
- **Bouton de diagnostic** dans la configuration : génère un fichier à envoyer au support (retour brut de la chaudière + informations d'encodage), mot de passe exclu.
- **Synchronisation via le démon** : « Synchroniser » respecte désormais le délai imposé par la chaudière et évite les erreurs de lecture concurrente.
- **Jauges et graphes** : bornes réalistes sur les mesures (températures extérieure / ambiante / eau, pourcentages…).

### Corrections
- **Caractères spéciaux des unités** : `°C` ne s'affiche plus `?C` (encodage UTF-8 fiabilisé de bout en bout).
- **Affichage de la version** du plugin : ne reste plus figé sur une ancienne valeur.
- **Typage des commandes** : les valeurs non numériques (heures, prévisions météo) ne sont plus traitées comme des nombres ; les réglages sans bornes réelles passent en saisie libre au lieu d'un curseur incohérent.
- **Installation des dépendances Python** fiabilisée — corrige le démarrage du démon sur une installation neuve.

## 0.0.2-beta — 2026-05-20

Mise à jour des liens de documentation.

## 0.0.1-beta — 2026-04-13

Première version beta publique.

## 1.0.0 — 2026-05-22

Première release stable.
