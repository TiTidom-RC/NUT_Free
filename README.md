# NUT Free

Plugin **Jeedom** de supervision d'onduleur (UPS) via le protocole **NUT** (Network UPS Tools).  
Deux modes de connexion : TCP direct vers le serveur NUT, ou à distance via le plugin **SSH-Manager**.

---

## Fonctionnalités

- Supervision complète de l'onduleur : état de la ligne, tensions, fréquences, charge, batterie, températures, temps restant
- **Mode NUT** (TCP direct) : daemon Python persistant (`nutfreed.py`) avec polling configurable et surveillance temps-réel de `ups.status` (StatusWatcher adaptatif)
- **Mode SSH** : collecte via le plugin SSH-Manager, sans daemon
- Auto-détection du nom de l'UPS (`upsc -l`) ou saisie manuelle
- Authentification upsd optionnelle (login / mot de passe)
- Rafraîchissement automatique du widget Jeedom à chaque mise à jour
- Heartbeat daemon configurable

---

## Variables supervisées

| Commande | Description | Unité |
|---|---|---|
| `Marque` | Marque + Modèle | — |
| `Model` | Modèle | — |
| `ups_serial` | Numéro de série | — |
| `ups_line` | Mode UPS (OL / OB / LB…) | — |
| `input_volt` | Tension en entrée | V |
| `input_freq` | Fréquence en entrée | Hz |
| `output_volt` | Tension en sortie | V |
| `output_freq` | Fréquence en sortie | Hz |
| `output_power` | Puissance en sortie | VA |
| `output_real_power` | Puissance réelle en sortie | W |
| `batt_charge` | Niveau de charge batterie | % |
| `batt_volt` | Tension de la batterie | V |
| `batt_temp` | Température de la batterie | °C |
| `ups_temp` | Température UPS | °C |
| `ups_load` | Charge onduleur | % |
| `batt_runtime` | Temps restant sur batterie | s |
| `batt_runtime_min` | Temps restant sur batterie | min |
| `timer_shutdown` | Temps restant avant arrêt | s |
| `timer_shutdown_min` | Temps restant avant arrêt | min |
| `beeper_stat` | État du beeper | — |

---

## Prérequis

- **Jeedom** ≥ 4.4.2
- **Python** ≥ 3.10 (géré via `pyenv` + `venv`)
- Plugin **SSH-Manager** requis uniquement pour le mode SSH
- Dépendances Python : `pynutclient==2.8.4`, `requests==2.32.5`

---

## Installation

1. Installer le plugin depuis le Market Jeedom
2. Cliquer sur **Installer les dépendances**
3. Démarrer le daemon depuis la page de configuration du plugin

---

## Configuration

Créer un équipement, renseigner :

- **Protocole de connexion** : `NUT` (TCP direct) ou `SSH` (via SSH-Manager)
- **Mode NUT** : adresse IP, port TCP (défaut : 3493), login/mot de passe upsd (optionnels)
- **Mode SSH** : sélectionner un hôte SSH configuré dans SSH-Manager
- **Auto-détection UPS** : activée par défaut (`upsc -l`), ou saisir le nom manuellement

Sauvegarder — toutes les commandes de supervision sont créées automatiquement.

---

## Liens

- <a href="https://titidom-rc.github.io/Documentation/fr_FR/Nut_free/index" target="_blank" rel="noopener noreferrer">Documentation</a>
- <a href="https://titidom-rc.github.io/Documentation/fr_FR/Nut_free/changelog" target="_blank" rel="noopener noreferrer">Changelog</a>
- <a href="https://community.jeedom.com/tag/plugin-Nut_free" target="_blank" rel="noopener noreferrer">Community Jeedom</a>

---

## Licence

AGPL — Auteur : <a href="https://github.com/TiTidom-RC" target="_blank" rel="noopener noreferrer">TiTidom</a>
