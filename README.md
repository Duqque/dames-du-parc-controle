# Les Dames du Parc · Contrôle des billets

Application web de contrôle des billets à l'entrée des événements de l'association **Les Dames du Parc**, supportrices du
Paris Saint-Germain. Les contrôleurs scannent le QR code (ou saisissent le numéro) depuis leur téléphone, sans rien installer ;
le serveur vérifie chaque billet et empêche toute double entrée, même quand plusieurs contrôleurs scannent le même billet au
même instant.

- **Serveur** : Apache 2.4 · **PHP** 8.2 ou supérieur · **Base** : MariaDB 10.4 ou supérieur
- **Interface** : HTML5, CSS3, JavaScript natif (aucun framework, aucun Node.js)
- **Lecture des QR codes** : détecteur natif du navigateur (Chrome/Android), ou [jsQR](https://github.com/cozmo/jsQR) (Safari/iPhone, Firefox), embarqués dans l'application

## Fonctionnalités

| Contrôleurs | Administrateurs |
|---|---|
| Connexion sécurisée | Tableau de bord en direct (billets valides, utilisés, annulés ; contrôles par résultat ; entrées par heure) |
| Choix de l'événement contrôlé | Historique des contrôles filtrable (date, événement, contrôleur, résultat, billet) et export CSV |
| **Scan du QR code** avec l'appareil photo | Recherche d'un billet (n°, code, titulaire, référence), détail, QR code, annulation, réactivation |
| **Saisie manuelle** du numéro (toujours disponible) | Import de billets CSV (doublons et lignes incorrectes signalés) et historique des imports |
| Résultat plein écran, couleur, son et vibration | Création de billets, planche de QR codes imprimable, export CSV des billets |
| | Événements (création, modification, désactivation) |
| | Comptes administrateurs et contrôleurs (création, modification, désactivation, suppression) |
| | Journal des opérations sensibles |

Résultats du contrôle : **Billet valide** (vert, accès autorisé) · **Déjà utilisé** (orange, avec l'heure et le contrôleur du premier
passage) · **Billet invalide** (rouge : inconnu, mal formé ou signature falsifiée) · **Billet annulé** (rouge) · **Autre événement**
(rouge) · **Erreur technique** (accès non autorisé). Seul « Billet valide » autorise l'entrée.

## Démarrage rapide

```bash
# 1. Base de données (voir docs/BASE-DE-DONNEES.md)
mariadb -u ddp_controle -p ddp_controle < database/schema.sql
# 2. Configuration
cp config/config.example.php config/config.php    # puis renseigner base, adresse, secret QR
# 3. Premier administrateur (voir docs/PREMIER-ADMINISTRATEUR.md)
php bin/create-admin.php presidente "Présidente"
# 4. Faire pointer la racine du site (DocumentRoot) sur le dossier public/, en HTTPS
```

## Organisation du projet

```
public/        Racine du site (seul dossier servi par Apache) : pages, API, CSS, JavaScript, images
  api/         Points d'accès JSON (connexion, contrôle, statistiques, import, export, événements, comptes…)
  admin/       Pages d'administration
  assets/      Feuilles de style, scripts, logo, bibliothèques QR (jsQR, qrcode-generator)
src/           Logique métier : authentification, billets, CSV, statistiques, journal (classes DDP\…)
templates/     Gabarit HTML commun
config/        config.example.php (modèle sans secret) · config.php (secrets, jamais versionné)
database/      schema.sql (script MariaDB complet) · demo-import.csv (exemple d'import)
bin/           create-admin.php · demo-data.php · backup.sh · restore.sh
tests/         run-tests.php (tests de sécurité, de validation, de double scan et de contrôles simultanés)
storage/       Journaux, sauvegardes et fichiers temporaires (hors accès public)
docs/          Documentation
```

## Documentation

| Document | Contenu |
|---|---|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Installation sur Apache (dont hébergement mutualisé, Infomaniak), HTTPS, configuration |
| [docs/BASE-DE-DONNEES.md](docs/BASE-DE-DONNEES.md) | Création de la base et du compte MariaDB, modèle de données |
| [docs/PREMIER-ADMINISTRATEUR.md](docs/PREMIER-ADMINISTRATEUR.md) | Création du premier compte administrateur (3 méthodes) |
| [docs/GUIDE-CONTROLEURS.md](docs/GUIDE-CONTROLEURS.md) | Guide d'utilisation pour les contrôleurs à l'entrée |
| [docs/SAUVEGARDE.md](docs/SAUVEGARDE.md) | Sauvegarde et restauration |
| [docs/SECURITE.md](docs/SECURITE.md) | Mesures de sécurité et liste de vérification avant mise en production |
| [docs/API.md](docs/API.md) | Description des points d'accès de l'API |
| [docs/TESTS.md](docs/TESTS.md) | Tests de recette, double scan, contrôles simultanés, jeu de démonstration |

## Licences des bibliothèques embarquées

- jsQR 1.4.0 — Apache License 2.0 (`public/assets/vendor/jsQR.LICENSE.txt`)
- qrcode-generator 1.4.4 — MIT, © Kazuhiko Arase (`public/assets/vendor/qrcode-generator.LICENSE.txt`)
