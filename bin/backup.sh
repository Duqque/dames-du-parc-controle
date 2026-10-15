#!/bin/sh
# Sauvegarde de la base MariaDB (compressée, horodatée) avec rotation.
#   sh bin/backup.sh                 (lit les identifiants dans config/config.php)
# À planifier chaque nuit (cron) et AVANT chaque événement. Conserve 30 sauvegardes par défaut (KEEP=30).
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${BACKUP_DIR:-$ROOT/storage/backups}"
KEEP="${KEEP:-30}"
mkdir -p "$DEST"
chmod 700 "$DEST"
eval "$(php -r '$c = require "'"$ROOT"'/config/config.php"; $d = $c["db"]; foreach (["host","port","name","user","password"] as $k) { echo "DB_".strtoupper($k)."=".escapeshellarg((string)($d[$k] ?? ""))."\n"; }')"
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "${DB_PORT:-3306}" "$DB_USER" "$DB_PASSWORD" > "$CNF"
DUMP="$(command -v mariadb-dump || command -v mysqldump)"
FILE="$DEST/ddp-controle-$(date +%Y%m%d-%H%M%S).sql.gz"
"$DUMP" --defaults-extra-file="$CNF" --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$FILE"
chmod 600 "$FILE"
ls -1t "$DEST"/ddp-controle-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs rm -f 2>/dev/null || true
echo "Sauvegarde : $FILE ($(du -h "$FILE" | cut -f1))"
