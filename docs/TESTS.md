# Tests

## Lancer la suite de recette

Sur un environnement de **test** (jamais sur la base de production) où l'application est servie par Apache :

```bash
BASE_URL=https://controle-test.example.org php tests/run-tests.php
```

Le script crée ses propres comptes, événements et billets (préfixe `test-`), joue tous les scénarios en conditions réelles
(requêtes HTTP vers Apache, plusieurs sessions de contrôleurs, requêtes simultanées), vérifie le résultat dans MariaDB, puis
supprime ses données. Code de sortie 0 si tout est conforme.

## Ce qui est vérifié (87 contrôles)

| Domaine | Vérifications |
|---|---|
| Accès protégés | API et pages internes refusées sans connexion (401 / redirection), page d'installation bloquée |
| Connexion | CSRF obligatoire, origine étrangère refusée, message générique (pas d'énumération), octet nul sans erreur serveur, cookie HttpOnly + SameSite=Strict (+ Secure en HTTPS), identifiant de session régénéré |
| Force brute | 5 échecs puis blocage 429, même avec le bon mot de passe |
| Rôles | un contrôleur n'accède à aucune API ni page d'administration |
| Contrôle des billets | valide (QR signé) → accepté une fois ; statut, heure et contrôleur enregistrés ; second scan → « déjà utilisé » avec l'heure du premier contrôle ; saisie manuelle (y compris par groupes de 4) ; casse respectée ; billet inconnu ; signature falsifiée ; billet annulé ; autre événement (non consommé) ; injections SQL et XSS refusées proprement ; CSRF obligatoire |
| **Double entrée** | **10 billets × 16 scans simultanés par 2 contrôleurs : exactement 1 entrée validée par billet**, 15 « déjà utilisé », 1 seule ligne « valide » au journal |
| Charge | 60 billets différents contrôlés simultanément : 60 entrées valides |
| Persistance | après déconnexion, nouvelle connexion à la base et nouvelle session, le billet reste « utilisé » |
| Statistiques | compteurs identiques aux données réelles de MariaDB ; filtres de l'historique ; recherche par numéro |
| Import CSV | importés / générés / doublons (fichier et base) / lignes incorrectes, rapport ligne par ligne ; réimport ; `.php`, binaire déguisé, fichier vide, en-tête inconnu refusés ; import interdit aux contrôleurs |
| Export CSV | formules neutralisées ; export de l'historique |
| Comptes | mot de passe faible refusé ; mot de passe haché ; désactivation immédiate (session ouverte comprise) ; pas d'auto-suppression |
| Panne | base indisponible → « erreur technique », accès non autorisé |
| En-têtes | CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy ; version de PHP masquée ; fichiers cachés refusés |

## Résultats obtenus avant livraison

- Apache 2.4.69 + PHP 8.5 + MariaDB 13 : **87 réussis, 0 échoué**.
- PHP 8.2.34 (serveur intégré de PHP, pour la compatibilité) : 86 réussis ; le seul écart, attendu, est la protection des
  fichiers cachés, assurée par `.htaccess` sous Apache et non par le serveur intégré de PHP.
- Racine du site sur le dossier du projet (hébergement mutualisé) : `config/`, `src/`, `storage/`, `database/`, `bin/`, `tests/`,
  `templates/`, `.git/` refusés (403), application fonctionnelle.
- Lecture des QR codes : QR généré par l'application relu à l'identique par le détecteur natif (Chrome) et par jsQR (iPhone).
- Installation : page `install.php` (jeton), `bin/create-admin.php`, sauvegarde puis restauration vérifiées.

## Tests manuels sur téléphone (à faire sur le serveur HTTPS)

1. Android (Chrome) et iPhone (Safari) : connexion, autorisation de la caméra, scan d'un QR code affiché sur un autre écran, puis
   imprimé (Administration > Billets > Imprimer les QR codes).
2. Refuser l'accès à la caméra : l'application bascule sur la saisie manuelle.
3. Deux téléphones scannent le même billet en même temps : un seul écran vert.
4. Couper le réseau du téléphone et scanner : écran gris « erreur technique » (accès non autorisé).

## Jeu de données de démonstration

```bash
php bin/demo-data.php                      # mots de passe aléatoires affichés une fois
DDP_DEMO_PASSWORD='Demo-…' php bin/demo-data.php
```

2 événements, 80 billets (5 déjà utilisés, 3 annulés), 3 comptes (`demo.admin`, `controle.porte1`, `controle.porte2`) ; codes et
contenus des QR codes dans `storage/tmp/demo-billets.csv`. Fichier d'import d'exemple (avec doublon et erreurs volontaires) :
`database/demo-import.csv`. **À ne jamais charger en production.**
