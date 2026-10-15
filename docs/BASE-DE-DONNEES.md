# Base de données MariaDB

## 1. Créer la base et le compte

Avec un compte d'administration MariaDB (en ligne de commande ou phpMyAdmin) :

```sql
CREATE DATABASE ddp_controle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ddp_controle'@'localhost' IDENTIFIED BY 'un-mot-de-passe-long-et-aléatoire';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES
   ON ddp_controle.* TO 'ddp_controle'@'localhost';
FLUSH PRIVILEGES;
```

Le compte de l'application n'a de droits que sur sa propre base. `CREATE`, `ALTER`, `INDEX` et `REFERENCES` ne servent qu'à
l'installation et aux mises à jour du schéma ; vous pouvez les retirer ensuite. `LOCK TABLES` sert à la sauvegarde.

Sur un hébergement mutualisé (Infomaniak, OVH…), la base et l'utilisateur se créent depuis l'interface de l'hébergeur ; notez
l'hôte (souvent différent de `localhost`), le nom de la base, l'utilisateur et le mot de passe.

## 2. Importer le schéma

```bash
mariadb -h HÔTE -u ddp_controle -p ddp_controle < database/schema.sql
```

ou, dans phpMyAdmin : base `ddp_controle` > Importer > `database/schema.sql`.

Le script est réexécutable (`CREATE TABLE IF NOT EXISTS`) et crée 7 tables.

## 3. Modèle de données

| Table | Rôle | Points clés |
|---|---|---|
| `users` | Comptes | `username` unique, `password_hash` (bcrypt via `password_hash()`), `role` = `admin` ou `controller`, `is_active` |
| `events` | Événements | nom, date (UTC), lieu, `is_active` (proposé au contrôle) |
| `tickets` | Billets | `token` **unique** (jeton aléatoire, sensible à la casse), `event_id`, `status` = `valid` / `used` / `cancelled`, `used_at`, `used_by` (contrôleur), `cancelled_at`, titulaire et référence facultatifs |
| `check_logs` | Toutes les tentatives de contrôle | résultat (`valid`, `already_used`, `invalid`, `cancelled`, `wrong_event`, `error`), mode (scan / saisie), contrôleur, événement, début du code et empreinte SHA-256 (jamais le code complet d'un billet inconnu), adresse IP, horodatage à la milliseconde |
| `imports` | Historique des imports CSV | fichier, nombres de lignes, importées, doublons, erreurs, rapport JSON |
| `audit_logs` | Opérations sensibles | connexions, comptes, imports, exports, annulations… |
| `login_attempts` | Limitation des connexions | échecs par identifiant et par adresse IP |
| `app_state` | État de la synchronisation avec le site | date et résultat du dernier chargement des billets |

Billets venant du site : `tickets.external_id` (identifiant sur le site), `places`, `birth_date`, `member_hash` (empreinte du jeton
de carte de membre), `cancel_reason` (`perte`, `annulation`, `supprimé`), `site_pending` (présence à renvoyer au site).

Intégrité : clés étrangères entre billets, événements, comptes, imports et journaux ; index sur les recherches fréquentes
(statut par événement, historique par date, contrôleur, événement) ; contrainte `chk_tickets_used` (un billet « utilisé » a
forcément une date d'utilisation). Supprimer un compte conserve l'historique de ses contrôles (contrôleur laissé vide).

Toutes les dates sont stockées en **UTC** et affichées dans le fuseau configuré (`Europe/Paris`).

## 4. Validation atomique d'un billet

```
BEGIN
  SELECT … FROM tickets WHERE token = ? FOR UPDATE        -- verrou exclusif sur la ligne du billet
  -- statut « valid » ?
  UPDATE tickets SET status='used', used_at=UTC_TIMESTAMP(), used_by=?
   WHERE id = ? AND status = 'valid'                     -- mise à jour conditionnelle, 1 ligne attendue
  INSERT INTO check_logs …                                 -- journal écrit sous le même verrou
COMMIT
```

Deux contrôleurs qui scannent le même billet au même instant sont sérialisés par le verrou : le premier obtient « valide », le
second lit « utilisé » et obtient « déjà utilisé ». Si MariaDB annule une transaction en conflit (interblocage, attente trop
longue), elle est rejouée jusqu'à 3 fois ; si le contrôle reste impossible, le résultat est « erreur technique » et **l'accès
n'est pas autorisé**. Ce fonctionnement est vérifié par les tests (16 scans simultanés d'un même billet × 10 billets).
