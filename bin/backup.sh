#!/usr/bin/env bash
# Nightly database backup. Keeps 30 days of compressed dumps in storage/backups.
#   15 2 * * * www-data /var/www/gpc-reserve/bin/backup.sh
# Copy storage/backups somewhere off this server too (NAS, Google Drive, etc.).
set -euo pipefail
cd "$(dirname "$0")/.."
eval "$(php -r 'require "src/bootstrap.php"; foreach (["host","port","name","user","password"] as $k) { echo "DB_".strtoupper($k)."=".escapeshellarg((string) GPC\App::config("db.$k")).PHP_EOL; }')"
mkdir -p storage/backups
FILE="storage/backups/gpc-reserve-$(date +%Y%m%d-%H%M).sql.gz"
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --no-tablespaces -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$FILE"
find storage/backups -name 'gpc-reserve-*.sql.gz' -mtime +30 -delete
echo "Backup written: $FILE"
