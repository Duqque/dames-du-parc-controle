# API PHP

Toutes les réponses sont en JSON (`Content-Type: application/json`, `Cache-Control: no-store`), sauf les exports (CSV).
Forme générale : `{ "ok": true, … }` ou `{ "ok": false, "error": "message lisible" }` avec un statut HTTP adapté :
401 (non connecté), 403 (rôle insuffisant ou jeton CSRF invalide), 404, 405 (méthode), 409 (conflit), 422 (données invalides),
429 (trop de tentatives), 503 (contrôle impossible).

Les requêtes `POST` exigent l'en-tête `X-CSRF-Token` (jeton fourni par `GET /api/session.php`, par la réponse de connexion,
et dans la balise `<meta name="csrf-token">` des pages) et un corps JSON (`Content-Type: application/json`), sauf l'import
(formulaire `multipart/form-data`).

| Méthode | Chemin | Rôle | Description |
|---|---|---|---|
| GET | `/api/session.php` | — | Compte connecté (ou `null`), jeton CSRF, événements actifs |
| POST | `/api/login.php` | — | `{username, password}` → connexion ; `{redirect}` |
| POST | `/api/logout.php` | connecté | Déconnexion |
| POST | `/api/validate-ticket.php` | contrôleur | `{code, event_id?, method: "scan"\|"manual"}` ou `{ticket_id, event_id, method: "list"}` → contrôle et validation atomique ; une entrée validée est envoyée au site après la réponse |
| GET | `/api/tickets-cache.php?event_id=` | contrôleur | Tous les billets de l'événement, sous forme **d'empreintes** SHA-256 des codes (jamais les codes eux-mêmes) : nom, statut, heure d'entrée ; compteurs ; état du lien avec le site. Synchronise d'abord avec le site (au plus toutes les 30 s) |
| GET | `/api/my-checks.php?event_id=` | contrôleur | Les 100 derniers contrôles du compte connecté |
| POST | `/api/sync.php` | contrôleur | Synchronisation immédiate avec le site (billets, présences en attente d'envoi) |
| GET | `/api/ticket-status.php?code=…` | contrôleur | Statut d'un billet sans le modifier |
| GET | `/api/ticket-status.php?id=…` | admin | Détail d'un billet, contenu du QR code, historique de ses contrôles |
| GET | `/api/dashboard.php?event_id=` | admin | Statistiques (billets, contrôles par résultat, entrées par heure) et derniers contrôles |
| GET | `/api/checks.php?event_id=&user_id=&result=&from=&to=&q=&page=` | admin | Historique des contrôles filtré et paginé |
| GET | `/api/export-checks.php?…` (mêmes filtres) | admin | Export CSV de l'historique |
| GET | `/api/export-tickets.php?event_id=` | admin | Export CSV des billets (code et contenu du QR code) |
| POST | `/api/import-tickets.php` | admin | `multipart` : `event_id`, `file` (CSV) → rapport d'import |
| GET | `/api/imports.php` | admin | Historique des imports |
| GET / POST | `/api/tickets.php` | admin | Recherche ; actions `create`, `cancel`, `reactivate`, `reset` |
| GET / POST | `/api/events.php` | contrôleur (lecture) / admin | Liste ; actions `create`, `update`, `delete` |
| GET / POST | `/api/users.php` | admin | Liste ; actions `create`, `update`, `password`, `activate`, `deactivate`, `delete` |
| GET | `/api/audit.php` | admin | Journal des opérations sensibles |

## Contrôle d'un billet

```http
POST /api/validate-ticket.php
X-CSRF-Token: …
Content-Type: application/json

{"code": "DDP1.SCAQWK87DMNW5EMDM8JVVSJ5.HM5jA2kB_2BtGD8Q", "event_id": 1, "method": "scan"}
```

```json
{"ok": true, "result": "already_used", "message": "Billet déjà utilisé : accès refusé.",
 "used_at": "21:04:12", "used_at_full": "20/10/2026 21:04:12", "used_by": "Porte 1",
 "ticket": {"id": 42, "holder_name": "Camille Martin", "reference": "CMD-1042", "event_id": 1,
            "event_name": "Watch party : PSG - Barça", "event_date": "20/10/2026 21:00", "status": "used"}}
```

`result` vaut `valid`, `already_used`, `invalid`, `cancelled`, `wrong_event` ou `error`. **Seul `valid` autorise l'entrée.**

## Lien avec lesdamesduparc.com

L'application appelle deux points d'accès du site, protégés par la clé partagée `SCANNER_API_KEY`
(en-tête `Authorization: Bearer …`) :

| Méthode | Chemin (site) | Description |
|---|---|---|
| GET | `/api/scanner/tickets` | Événements publiés (de la veille à J+90) et tous leurs billets : code à 12 chiffres, statut, titulaire, n° de membre, places, présence ; billets remplacés ou annulés |
| POST | `/api/scanner/checkin` | `{code, scannedAt, controller}` → marque la participante **présente** ; réponse `present`, `already`, `cancelled`, `revoked` ou `unknown` |

Si le site est injoignable au moment d'une entrée, l'entrée reste validée dans l'application et l'envoi est rejoué à la
synchronisation suivante (colonne `tickets.site_pending`).

## Contenu des codes

Billets du site : **12 chiffres**, imprimés sous le code-barres Code 128 du billet électronique. Carte de membre : QR code menant à
`https://lesdamesduparc.com/verification/<jeton>` ; l'application retrouve le billet de la membre pour l'événement contrôlé.

Billets créés dans l'application :

`DDP1.<jeton>.<signature>` : préfixe de version, jeton du billet (24 caractères), signature HMAC-SHA256 tronquée (16 caractères,
base64url) calculée avec `qr_secret`. Le serveur accepte aussi le jeton seul (saisie manuelle) ou un code importé d'un autre
système (6 à 64 caractères : lettres, chiffres, `-`, `_`).
