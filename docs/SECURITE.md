# Sécurité

## Mesures en place

| Domaine | Mesure |
|---|---|
| Base de données | PDO, requêtes **préparées** uniquement (préparations natives, `ATTR_EMULATE_PREPARES = false`) ; compte MariaDB limité à sa base |
| Mots de passe | `password_hash()` / `password_verify()` (bcrypt), réhachage automatique si l'algorithme évolue ; 10 caractères minimum, lettres et chiffres ; message d'erreur identique pour un identifiant inconnu ou un mauvais mot de passe, temps de réponse constant |
| Connexion | limitation : 5 échecs par identifiant ou 20 par adresse IP en 15 minutes, puis blocage temporaire (429) ; tentatives et blocages journalisés |
| Sessions | identifiant régénéré à la connexion ; cookie `HttpOnly`, `Secure` (HTTPS), `SameSite=Strict` ; mode strict ; expiration après 4 h d'inactivité et 14 h au total ; compte relu en base à chaque requête (un compte désactivé perd l'accès immédiatement) |
| CSRF | jeton par session exigé sur toute requête qui modifie des données (en-tête `X-CSRF-Token`), comparé en temps constant ; contrôle de l'en-tête `Origin` ; cookie `SameSite=Strict` |
| XSS | échappement systématique (`htmlspecialchars` côté PHP, échappement côté JavaScript) ; **Content-Security-Policy** stricte : scripts et styles uniquement depuis le site, aucun script en ligne |
| Autorisations | refus par défaut : chaque point d'accès vérifie la session et le rôle (401 sans session, 403 sans le rôle) ; garde-fous (pas d'auto-suppression, toujours un administrateur actif) |
| Billets | jetons aléatoires de 24 caractères (~120 bits, `random_int`), jamais d'identifiant consécutif dans les QR codes ; QR codes **signés** (HMAC-SHA256, secret serveur) : un QR falsifié est refusé ; validité décidée **uniquement par le serveur** |
| Double entrée | transaction MariaDB, verrou de ligne `SELECT … FOR UPDATE`, mise à jour conditionnelle `WHERE status = 'valid'`, journal écrit sous le même verrou ; transaction rejouée en cas d'interblocage |
| Erreurs | tout échec technique donne « erreur technique, accès non autorisé » ; aucun détail technique renvoyé à l'utilisateur en production (`debug = false`), détails dans `storage/logs/` |
| Import CSV | extension `.csv`, type de contenu vérifié (`finfo`), octets nuls refusés, 5 Mo et 20 000 lignes maximum, encodage contrôlé, chaque ligne validée, valeurs commençant par `= + - @` refusées, insertion en transaction |
| Export CSV | cellules commençant par `= + - @` tabulation ou retour chariot préfixées d'une apostrophe (pas d'exécution de formule dans un tableur) |
| Fichiers | seul `public/` est servi ; `config/`, `src/`, `storage/`, `database/`, `bin/`, `tests/`, `templates/` protégés par `.htaccess` ; fichiers cachés et extensions sensibles refusés ; secrets uniquement dans `config/config.php` (non versionné) |
| En-têtes HTTP | CSP, `X-Frame-Options: DENY` et `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy` (caméra uniquement pour le site), HSTS en HTTPS, version de PHP masquée, `noindex` |
| Journalisation | `audit_logs` : connexions, blocages, comptes, imports, exports, impressions, annulations et remises à zéro de billets ; `check_logs` : chaque tentative de contrôle (le code complet d'un billet inconnu n'est jamais stocké : 4 premiers caractères et empreinte SHA-256) |
| Lien avec le site | clé partagée d'au moins 32 caractères (`SCANNER_API_KEY`), comparée en temps constant par le site ; uniquement en HTTPS ; le site ne transmet que ce qui sert au contrôle (nom, date de naissance, n° de membre, places, empreinte du jeton de carte de membre) |
| Billets sur le téléphone | la liste chargée sur le téléphone contient des **empreintes** SHA-256 des codes, jamais les codes : elle ne permet pas de fabriquer un billet. L'affichage anticipé du nom n'est qu'un aperçu : **la décision est toujours celle du serveur** |
| Données personnelles | nom du titulaire, référence facultative et, pour les billets du site, date de naissance, n° de membre et nombre de places ; aucune donnée de paiement |

## Liste de vérification avant la mise en production

- [ ] HTTPS actif, redirection `http://` → `https://`, certificat valide
- [ ] Même clé `SCANNER_API_KEY` sur le site et dans `config/config.php` (`site.api_key`), jamais communiquée par e-mail en clair
- [ ] `config/config.php` : `debug => false`, `session.secure_cookie => true`, `qr_secret` aléatoire (64 caractères hexadécimaux), `install_token => ''`
- [ ] `/config/config.php`, `/src/App.php`, `/storage/` répondent 403 ou 404
- [ ] Compte MariaDB dédié, mot de passe long, droits limités à la base
- [ ] Mots de passe des comptes nominatifs, aucun compte de démonstration (`demo.admin`, `controle.porte1`…) en production
- [ ] Sauvegarde automatique planifiée et testée (`docs/SAUVEGARDE.md`)
- [ ] `display_errors = Off` dans la configuration PHP de l'hébergement
