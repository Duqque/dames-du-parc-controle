# Sauvegarde et restauration

Toutes les données (comptes, événements, billets, historique des contrôles, journaux) sont dans MariaDB. Les fichiers de
l'application se réinstallent à l'identique ; seuls `config/config.php` (à conserver en lieu sûr, hors du serveur) et la base sont
à sauvegarder.

## Sauvegarder

```bash
sh bin/backup.sh
```

- lit les identifiants dans `config/config.php` (aucun mot de passe sur la ligne de commande ni dans l'historique) ;
- produit `storage/backups/ddp-controle-AAAAMMJJ-HHMMSS.sql.gz` (sauvegarde cohérente à chaud, `--single-transaction`) ;
- garde les 30 dernières sauvegardes (`KEEP=60 sh bin/backup.sh` pour en garder 60 ; `BACKUP_DIR=/autre/dossier` pour changer
  d'emplacement).

**Quand ?** Chaque nuit, et **juste avant et juste après chaque événement**.

Tâche planifiée (cron), tous les jours à 3 h :

```
0 3 * * * cd /chemin/vers/controle-ddp && sh bin/backup.sh >> storage/logs/backup.log 2>&1
```

Sur Infomaniak : Manager > Hébergement > Tâches planifiées (cron), ou utiliser aussi les sauvegardes automatiques de l'hébergeur.
Copiez régulièrement les sauvegardes hors du serveur (ordinateur de l'association, stockage chiffré).

Sans accès SSH : phpMyAdmin > base `ddp_controle` > Exporter > méthode rapide, format SQL.

## Restaurer

```bash
sh bin/restore.sh storage/backups/ddp-controle-20261020-180000.sql.gz
```

Le script demande de taper `OUI` : **la base actuelle est remplacée** par la sauvegarde. Faites d'abord une sauvegarde de l'état
actuel si vous voulez pouvoir revenir en arrière.

Sans accès SSH : décompressez le fichier `.sql.gz`, puis phpMyAdmin > Importer.

## Vérifier une sauvegarde

Restaurer de temps en temps une sauvegarde sur une base de test, puis lancer les tests (`docs/TESTS.md`) ou simplement se
connecter et consulter le tableau de bord. Une sauvegarde jamais testée n'est pas une sauvegarde.

## Cas particuliers

- **Changement de serveur** : installer l'application, copier `config/config.php` (en adaptant l'accès à la base), restaurer la
  dernière sauvegarde.
- **Le secret `qr_secret` doit être conservé** : il signe les QR codes. Le perdre rend la signature des QR codes déjà
  distribués invalide (la saisie manuelle du code reste possible).
