# MyOkoTouch — Documentation

## Introduction

**MyOkoTouch** est un plugin Jeedom qui intègre les chaudières à pellets **Okofen** équipées du firmware **Pelletronic Touch**.

Il interroge périodiquement l'API HTTP JSON de la chaudière, crée automatiquement un équipement Jeedom par composant détecté, et expose les variables sous forme de commandes info (lecture) et action (écriture de consignes).
Les envois de commande sont gérés par le démon, pour les intégrer au flux de communication avec la chaudière.

Ce plugin a été développé par Thoto pour faciliter l'intégration des chaudières Okofen avec Jeedom. L'environnement comprenait une chaudière, 3 circuits de chauffage, et 2 bouclages sanitaires.
Les autres composants existants dans l'écosystème Okofen sont toutefois détectés automatiquement, mais pourraient nécessiter des ajustements dans les versions ultérieures.
N'hésitez pas à contribuer ou à signaler des problèmes sur le forum Community Jeedom.

---

## Prérequis

- **Jeedom ≥ 4.4**
- Chaudière Okofen avec firmware **Pelletronic Touch** (accès JSON activé)
- Accès réseau local entre Jeedom et la chaudière (même réseau LAN)
- Port JSON et mot de passe configurés sur la chaudière (interface locale de la chaudière)
- Python 3 (installé automatiquement par Jeedom lors de l'installation du plugin)

---

## Installation

1. Dans Jeedom, aller dans **Plugins > Gestion des plugins > Market**
2. Rechercher **MyOkoTouch** et installer depuis le canal souhaité (beta ou stable)
3. Activer le plugin après installation
4. Configurer le plugin (voir section suivante)

---

## Configuration globale

Accessible via **Plugins > Gestion des plugins > MyOkoTouch > Configuration** (icône engrenage).

| Paramètre | Description | Valeur par défaut |
|-----------|-------------|-------------------|
| **Adresse IP / Hostname** | Adresse IP ou nom DNS de la chaudière sur le réseau local | — |
| **Port JSON** | Port de l'API JSON (configurable sur la chaudière) | `4321` |
| **Mot de passe** | Mot de passe défini dans les réglages de la chaudière | — |
| **Délai de polling (ms)** | Intervalle entre deux lectures de l'API (minimum 2500 ms imposé par Okofen) | `3000` |
| **Port socket démon** | Port TCP utilisé en interne par le démon Python | `55009` |
| **Mode commandes par défaut** | Mode appliqué aux nouveaux équipements lors de la synchronisation | `standard` |

### Bouton « Tester la connexion »

Vérifie l'accès à la chaudière avec les paramètres saisis et affiche la liste des composants détectés. **Les paramètres sont automatiquement sauvegardés avant le test.**

### Bouton « Générer un fichier de diagnostic »

Génère et télécharge un fichier `.json` contenant le **retour brut** de la chaudière (avec les informations d'encodage) et l'inventaire des commandes Jeedom existantes. Ce fichier est destiné à être **transmis au support** en cas de problème d'affichage, d'encodage ou de valeurs incohérentes — il permet d'analyser ce que renvoie réellement la chaudière sans y accéder directement. **Le mot de passe n'y figure pas et l'adresse est masquée.**

---

## Synchronisation des équipements

Depuis la liste des équipements (**Plugins > Protocole domotique > MyOkoTouch**), cliquer sur **Synchroniser**.

Le plugin interroge la chaudière et crée automatiquement un équipement Jeedom par composant détecté.

Composants typiques selon la configuration de l'installation :

| Code | Description |
|------|-------------|
| `system` | Système général (température extérieure, mode global…) |
| `weather` | Météo / prévisions intégrées |
| `pe` | Chaudière à pellets (température, état, modulation, niveau pellets…) |
| `hk1`, `hk2`… | Circuits de chauffage |
| `ww1` | Ballon eau chaude sanitaire (ECS) |
| `circ1` | Pompe de circulation |
| `pu1` | Accumulateur / tampon |
| `sk1` | Capteur solaire |

> Les équipements ne peuvent pas être créés manuellement — uniquement via la synchronisation.

---

## Équipements et commandes

Chaque équipement correspond à un composant Okofen.

- **Commandes info** : retournent la valeur courante (température, état, consigne…) mise à jour à chaque cycle de polling
- **Commandes action** : permettent d'envoyer une nouvelle valeur à la chaudière (ex. modifier une consigne de température)

> Les variables préfixées `L_` sur la chaudière sont en lecture seule — aucune commande action n'est créée pour elles.

### Valeurs physiques

Les valeurs brutes de l'API sont automatiquement converties via le facteur Okofen (ex. : `493 × 0.1 = 49.3 °C`).

---

## Mode Standard / Expert

Le mode définit quelles commandes sont créées sur un équipement.

| Mode | Comportement |
|------|-------------|
| **Standard** | Seules les commandes les plus utiles sont créées (liste filtrée par type de composant) |
| **Expert** | Toutes les variables remontées par la chaudière sont créées |

- Le **mode par défaut global** se configure dans la configuration du plugin
- Chaque équipement peut avoir son propre mode, indépendamment du réglage global
- Changer le mode d'un équipement puis sauvegarder recréé les commandes selon le nouveau mode
- Sur chaque équipement un bouton "Régénérer les commandes" permet d'effacer et recréer les commandes, soit en utilisant un nom desciptif, soit en utilisant le nom brut de la variable

---

## Démon

Le démon Python tourne en arrière-plan et gère la communication avec la chaudière.

- **Démarrage** : automatique au chargement du plugin, ou manuel depuis la page de configuration

---

## Problèmes connus

### Caractères mutilés sur les anciens firmwares

Certains firmwares Okofen anciens (**V3.00 confirmé**, potentiellement d'autres) renvoient des **caractères mutilés** dans leurs réponses JSON : le caractère d'origine est remplacé par un `?` **à la source** (dans la chaudière elle-même). Cela peut toucher :

- les **unités** — par exemple `°C` qui s'affiche `?C` ;
- les **caractères accentués** (libellés d'état, noms de circuits…).

Le plugin corrige automatiquement les unités connues (`?C` → `°C`) lors de la **synchronisation**. En revanche, les caractères accentués mutilés ne sont **pas récupérables** : l'information est perdue au niveau de la chaudière. Mettre à jour le firmware de la chaudière (lorsque c'est possible) résout le problème à la racine.

> Si vous rencontrez un cas non couvert, générez un **fichier de diagnostic** (voir *Configuration globale*) et transmettez-le au support : il contient les octets bruts renvoyés par votre chaudière.

