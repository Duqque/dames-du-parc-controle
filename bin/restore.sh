#!/bin/sh
# Restauration d'une sauvegarde : REMPLACE le contenu actuel de la base.
#   sh bin/restore.sh storage/backups/ddp-controle-AAAAMMJJ-HHMMSS.sql.gz
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILE="${1:?Indiquez le fichier de sauvegarde (.sql.gz)}"
[ -f "$FILE" ] || { echo "Fichier introuvable : $FILE" >&2; exit 1; }
eval "$(php -r '$c = require "'"$ROOT"'/config/config.php"; $d = $c["db"]; foreach (["host","port","name","user","password"] as $k) { echo "DB_".strtoupper($k)."=".escapeshellarg((string)($d[$k] ?? ""))."\n"; }')"
printf "Restaurer %s dans la base « %s » ? Les données actuelles seront remplacées. Tapez OUI : " "$FILE" "$DB_NAME"
read -r ANSWER
[ "$ANSWER" = "OUI" ] || { echo "Annulé."; exit 1; }
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "${DB_PORT:-3306}" "$DB_USER" "$DB_PASSWORD" > "$CNF"
CLIENT="$(command -v mariadb || command -v mysql)"
gunzip -c "$FILE" | "$CLIENT" --defaults-extra-file="$CNF" --default-character-set=utf8mb4 "$DB_NAME"
echo "Restauration terminée."
