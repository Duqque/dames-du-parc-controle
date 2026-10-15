# Installation sur Apache

## 1. Prérequis

| Élément | Version | Remarques |
|---|---|---|
| Apache | 2.4 | `mod_rewrite` et `mod_headers` activés, `AllowOverride All` (ou au moins `FileInfo Options Indexes AuthConfig`) |
| PHP | 8.2 ou supérieur | testé en 8.2 et 8.5 ; module Apache ou PHP-FPM |
| Extensions PHP | `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json`, `session` | `curl` uniquement pour lancer les tests |
| MariaDB | 10.4 ou supérieur | moteur InnoDB ; MySQL 8 convient aussi |
| HTTPS | certificat valide | **obligatoire** : sans HTTPS, les navigateurs refusent l'accès à la caméra |

Aucun Node.js, Composer ou outil de compilation n'est nécessaire : le dossier se dépose tel quel.

## 2. Déposer les fichiers

Déposez le dossier complet sur le serveur, **en dehors** de la racine publique si possible, par exemple :

```
/srv/www/controle-ddp/          ← dossier du projet
/srv/www/controle-ddp/public/   ← racine du site (DocumentRoot)
```

Droits : le serveur web doit pouvoir **écrire** dans `storage/` (journaux, sauvegardes) et seulement **lire** le reste.

```bash
chmod -R u=rwX,go=rX controle-ddp
chmod -R u=rwX,go= controle-ddp/storage controle-ddp/config
```

## 3. Choisir la racine du site

**Recommandé** : la racine du site (DocumentRoot) pointe sur `public/`. Le code, la configuration et les journaux ne sont alors
jamais accessibles depuis le web.

```apache
<VirtualHost *:443>
    ServerName controle.lesdamesduparc.com
    DocumentRoot /srv/www/controle-ddp/public
    <Directory /srv/www/controle-ddp/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/controle.lesdamesduparc.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/controle.lesdamesduparc.com/privkey.pem
</VirtualHost>
```

**Hébergement mutualisé sans choix de la racine** : déposez le projet à la racine du site. Le fichier `.htaccess` placé à la
racine du projet redirige toutes les requêtes vers `public/` et interdit l'accès à `config/`, `src/`, `storage/`, `database/`,
`bin/`, `tests/`, `templates/` et `docs/` (chacun de ces dossiers contient en plus son propre `.htaccess` « Require all denied »).

**Infomaniak (hébergement Web)** : Manager > Hébergement > Sites > le site > « Dossier racine » : indiquez
`/sites/controle.lesdamesduparc.com/public` ; activez le certificat Let's Encrypt et la redirection HTTPS ; choisissez PHP 8.2 ou
plus dans « Gérer PHP ». Créez la base MariaDB dans la rubrique « Bases de données ».

## 4. Base de données

Voir [BASE-DE-DONNEES.md](BASE-DE-DONNEES.md) : création de la base et de l'utilisateur, puis import de `database/schema.sql`.

## 5. Configuration

```bash
cp config/config.example.php config/config.php
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'      # secret des QR codes (qr_secret)
```

Dans `config/config.php`, renseignez :

| Clé | Valeur |
|---|---|
| `db.host`, `db.port`, `db.name`, `db.user`, `db.password` | accès MariaDB |
| `app_url` | adresse publique, en `https://` |
| `qr_secret` | les 64 caractères générés ci-dessus (à conserver : le changer invalide les QR codes déjà distribués) |
| `session.secure_cookie` | `true` (laisser `true` en production) |
| `timezone` | `Europe/Paris` |
| `trusted_proxies` | adresses IP d'un éventuel mandataire (répartiteur de charge) ; vide sinon |
| `debug` | `false` en production |

`config/config.php` n'est jamais versionné (`.gitignore`) et n'est pas accessible depuis le web.

## 6. Lien avec lesdamesduparc.com

1. Générez une clé (au moins 32 caractères) : `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`
2. **Sur le site** (Infomaniak, variables d'environnement de l'application Node.js), ajoutez `SCANNER_API_KEY=<la clé>` puis
   redémarrez le site.
3. **Dans l'application**, `config/config.php`, bloc `site` :

| Clé | Valeur |
|---|---|
| `site.url` | `https://lesdamesduparc.com` |
| `site.api_key` | la **même** clé |
| `site.sync_seconds` | `30` (fréquence maximale de rechargement des billets) |
| `site.timeout` | `6` (secondes) |

Les billets se chargent dès qu'un contrôleur ouvre l'application. Facultatif : tâche planifiée `php bin/sync.php` toutes les
5 minutes (Infomaniak : Manager > Tâches planifiées), pour que les billets soient déjà à jour avant l'ouverture des portes.

Contrôle : Profil > « Billets du site » doit indiquer « À jour (…) ». « Lien avec le site non configuré » signifie que la clé est
absente ; « Erreur » qu'elle est différente de celle du site ou que le site est injoignable.

## 7. Premier administrateur

Voir [PREMIER-ADMINISTRATEUR.md](PREMIER-ADMINISTRATEUR.md).

## 8. Vérifications après installation

1. `https://controle.lesdamesduparc.com/` affiche la page de connexion (et `http://` redirige vers `https://`).
2. `https://controle.lesdamesduparc.com/config/config.php` et `/src/App.php` répondent **403** ou **404**.
3. Connexion avec le compte administrateur, puis depuis un téléphone : l'événement du site apparaît, « N billets chargés » ;
   scan du code-barres d'un billet de test : « Entrée validée », la participante est **présente** dans Admin > Événements >
   Présences du site ; second scan : « Déjà utilisé ».
4. Optionnel, sur un environnement de test : `BASE_URL=https://… php tests/run-tests.php` (voir [TESTS.md](TESTS.md)).

## 9. Mise à jour de l'application

Sauvegardez la base (`sh bin/backup.sh`), remplacez les fichiers en conservant `config/config.php` et `storage/`, importez les
nouvelles migrations de `database/migrations/` (une seule fois chacune, dans l'ordre), puis rechargez une page : les fichiers CSS
et JavaScript portent un numéro de version (`?v=…`) qui force leur rafraîchissement.

Depuis une version antérieure au lien avec le site : importez `database/migrations/002_lien_site.sql` (phpMyAdmin > Importer,
ou `mariadb … < database/migrations/002_lien_site.sql`) et ajoutez le bloc `site` à `config/config.php`
(voir `config/config.example.php`).

## 10. Réglages Apache conseillés pour un grand événement

Chaque contrôle est une requête très courte (quelques millisecondes). Prévoir au moins 2 processus PHP par contrôleur actif
(`MaxRequestWorkers` pour le module PHP, `pm.max_children` pour PHP-FPM). Une connexion Internet stable est indispensable à l'entrée :
le contrôle ne fonctionne pas hors ligne (un billet ne peut être déclaré valide que par le serveur).
