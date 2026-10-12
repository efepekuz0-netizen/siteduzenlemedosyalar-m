#!/usr/bin/env bash
#
# Bayiinet — günlük yedek (veritabanı + yüklenen dosyalar)
#
# Kurulum (cron):
#   sudo crontab -e
#   30 3 * * * APP_DIR=/var/www/bayiinet BACKUP_DIR=/var/backups/bayiinet bash /var/www/bayiinet/deploy/oracle/backup.sh >> /var/log/bayiinet-backup.log 2>&1
#
# Geri yükleme:
#   gunzip -c /var/backups/bayiinet/db-YYYYmmdd-HHMM.sql.gz | mysql -u bayiinet -p bayiinet
#   tar -xzf /var/backups/bayiinet/storage-YYYYmmdd-HHMM.tar.gz -C /var/www/bayiinet/storage

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/bayiinet}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/bayiinet}"
KEEP_DAYS="${KEEP_DAYS:-14}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }

mkdir -p "$BACKUP_DIR"
cd "$APP_DIR"

# .env'den değerleri oku (güvenli: sadece gerekli anahtarlar)
# `|| true` şart: `set -e` altında başarısız komut değişikliği betiği düşürür.
envval() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true; }

STAMP="$(date +%Y%m%d-%H%M)"
DB_NAME="$(envval DB_DATABASE)"
DB_USER="$(envval DB_USERNAME)"
DB_PASS="$(envval DB_PASSWORD)"
DB_HOST="$(envval DB_HOST)"
DB_PORT="$(envval DB_PORT)"

if [[ -n "${DB_NAME:-}" && -n "${DB_USER:-}" ]]; then
    log "Veritabanı yedeği: $DB_NAME"
    MYSQL_PWD="${DB_PASS:-}" mysqldump \
        --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" \
        --single-transaction --quick --routines --triggers \
        -u "$DB_USER" "$DB_NAME" | gzip -9 > "$BACKUP_DIR/db-$STAMP.sql.gz"
    log "  → $BACKUP_DIR/db-$STAMP.sql.gz ($(du -h "$BACKUP_DIR/db-$STAMP.sql.gz" | cut -f1))"
else
    log "UYARI: .env içinde DB bilgileri okunamadı, veritabanı yedeği atlandı."
fi

log "Yüklenen dosyalar (storage/app) yedekleniyor"
tar -czf "$BACKUP_DIR/storage-$STAMP.tar.gz" -C "$APP_DIR/storage" app 2>/dev/null || true
log "  → $BACKUP_DIR/storage-$STAMP.tar.gz"

log "Eski yedekler temizleniyor ($KEEP_DAYS günden eski)"
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime "+$KEEP_DAYS" -delete
find "$BACKUP_DIR" -name 'storage-*.tar.gz' -mtime "+$KEEP_DAYS" -delete

log "Yedek tamamlandı."
