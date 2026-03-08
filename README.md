# NUT Free

Plugin **Jeedom** de supervision d'onduleur (UPS) via le protocole **NUT** (Network UPS Tools).  
Deux modes de connexion : TCP direct vers le serveur NUT, ou à distance via le plugin **SSH-Manager**.

---

## Fonctionnalités

- Supervision complète de l'onduleur : statut, tensions, fréquences, charge, batterie, temperaturas, autonomie, puissance
- **Mode NUT** (TCP direct) : daemon Python persistant (`nutfreed.py`) avec polling configurable et surveillance temps-réel de `ups.status` (StatusWatcher adaptatif, 2–5s)
- **Mode SSH** : collecte synchrone via le plugin SSH-Manager, sans daemon — fréquence configurable via expression cron + délai aléatoire d'exécution
- **Synchronisation automatique** des commandes depuis l'onduleur (`upsc`, `upsrw`, `upscmd -l`)
- **Commandes dynamiques** : toutes les variables info, RW et instcmd supportées par l'onduleur
- **Variables dérivées** : autonomie en minutes, statut traduit (`ups_status_label`), variables `_min` pour les timers
- Auto-détection du nom de l'UPS (`upsc -l`) ou saisie manuelle
- Authentification upsd optionnelle (login / mot de passe)
- Actions sur l'onduleur : `setrwvar` (variables RW) et `instcmd` (beeper, test batterie, arrêt/redémarrage…)
- Retour des résultats de commandes dans la commande `Retour Commande` (`cmd_result`)
- Rafraîchissement manuel via la commande `Rafraîchir`
- Heartbeat daemon configurable (mode NUT)

---

## Commandes statiques (créées à l'installation)

| logicalId | Nom | Type | Description |
|---|---|---|---|
| `refresh` | Rafraîchir | action | Déclenche une collecte immédiate |
| `device_mfr` | Fabricant | info string | `device.mfr` |
| `device_model` | Modèle | info string | `device.model` |
| `ups_serial` | Numéro Série | info string | `ups.serial` |
| `ups_status` | Code NUT | info string | `ups.status` brut (OL, OB, LB…) |
| `ups_status_label` | Statut Onduleur | info string | Traduction française de `ups.status` |
| `ups_load` | Charge Onduleur | info numeric | `ups.load` (%) |
| `battery_charge` | Charge Batterie | info numeric | `battery.charge` (%) |
| `battery_runtime` | Autonomie Batterie | info numeric | `battery.runtime` (sec) |
| `battery_runtime_min` | Autonomie Batterie (min) | info numeric | Dérivé de `battery_runtime` (min) |
| `cmd_result` | Retour Commande | info string | Résultat de la dernière commande RW ou instcmd |

Les commandes supplémentaires (tensions, fréquences, températures, variables RW, instcmd…) sont créées dynamiquement lors de la synchronisation avec l'onduleur.

---

## Prérequis

- **Jeedom** ≥ 4.4.8
- **Python** `3.12.12` (installé automatiquement via `pyenv` + `venv`)
- Plugin **SSH-Manager** requis uniquement pour le mode SSH
- Dépendances Python : `pynutclient==2.8.4`, `requests==2.32.5`

---

## Installation

1. Installer le plugin depuis le Market Jeedom
2. Cliquer sur **Installer les dépendances**
3. Démarrer le daemon depuis la page de configuration du plugin (mode NUT uniquement)

---

## Configuration du plugin

| Paramètre | Description | Défaut |
|---|---|---|
| Port Socket Interne | Port de communication PHP ↔ daemon | `55113` |
| Intervalle de polling | Fréquence de collecte complète (mode NUT) | `60` s |
| Intervalle du surveillant de statut | Fréquence du StatusWatcher `ups.status` (mode NUT) | `5` s |
| Fréquence des cycles | Facteur multiplicateur des cycles internes du daemon | `1.0` (Normal) |
| Délai Aléatoire (collecte SSH) | Délai aléatoire d'exécution avant chaque collecte SSH (évite la surcharge système) | `15` s (0 = désactivé) |

---

## Configuration d'un équipement

- **Protocole de connexion** : `NUT` (TCP direct) ou `SSH` (via SSH-Manager)
- **Mode NUT** : adresse IP du serveur NUT, port TCP (défaut : 3493), login/mot de passe upsd (optionnels)
- **Mode SSH** : sélectionner un hôte configuré dans SSH-Manager, définir la fréquence de polling (expression cron, défaut : `* * * * *`)
- **Auto-détection UPS** : activée par défaut (`upsc -l`), ou saisir le nom manuellement
- **Utilisateur / Mot de passe NUT** : optionnels, utilisés pour `setrwvar` et `instcmd`

Sauvegarder → toutes les commandes statiques sont créées automatiquement.  
Cliquer sur **Synchroniser les Commandes** pour créer les commandes dynamiques propres à l'onduleur.

---

## Limitations du mode SSH

Le mode SSH est synchrone et cadencé par cron (minimum 1 minute). La détection d'une coupure secteur peut donc prendre jusqu'à la prochaine exécution du cron, contre 2–5s en mode NUT. Les alertes et scénarios Jeedom fonctionnent normalement dès la collecte effectuée.

---

## Liens

- <a href="https://titidom-rc.github.io/Documentation/fr_FR/Nut_free/index" target="_blank" rel="noopener noreferrer">Documentation</a>
- <a href="https://titidom-rc.github.io/Documentation/fr_FR/Nut_free/changelog" target="_blank" rel="noopener noreferrer">Changelog</a>
- <a href="https://community.jeedom.com/tag/plugin-Nut_free" target="_blank" rel="noopener noreferrer">Community Jeedom</a>

---

## Licence

AGPL — Auteur : <a href="https://github.com/TiTidom-RC" target="_blank" rel="noopener noreferrer">TiTidom</a>

