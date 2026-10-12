#!/usr/bin/env bash
#
# Bayiinet — kod güncellemesi (deploy) betiği
#
# Kullanım (GitHub'dan güncelleme):
#   cd /var/www/bayiinet
#   sudo bash deploy/oracle/deploy.sh
#
# Yaptığı iş:
#   1) git pull (main)
#   2) composer install (üretim)
#   3) migrate --force
#   4) önbellekleri yeniden üretir (config/route/view)
#   5) servisleri yeniler: php-fpm (OPcache sıfırlanır), kuyruk işçisi, zamanlayıcı
#   6) teşhis raporunu yazdırır
#
# Not: php ini dosyasında opcache.validate_timestamps=0 olduğu için kod
# değişikliği ancak php-fpm yeniden yüklenince etkili olur — betik bunu yapar.

set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
APP_USER="${APP_USER:-www-data}"
PHP_VER="8.3"
GIT_BRANCH="${GIT_BRANCH:-main}"
# Sunucuda /usr/bin/php her zaman olmayabilir (php8.3-cli → /usr/bin/php8.3).
PHP_BIN="${PHP_BIN:-$(command -v "php${PHP_VER}" || command -v php || echo "/usr/bin/php${PHP_VER}")}"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mHATA: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "sudo ile çalıştırın: sudo bash deploy/oracle/deploy.sh"
cd "$APP_DIR"
[[ -f artisan ]] || die "$APP_DIR bir Laravel projesi değil."

log "1/6 Bakım modu açılıyor"
sudo -u "$APP_USER" "$PHP_BIN" artisan down --render="errors::503" --retry=30 || warn "down atlandı"

log "2/6 Kod güncelleniyor (git pull origin $GIT_BRANCH)"
if [[ -d .git ]]; then
    git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
    sudo -u "$APP_USER" git fetch --all --prune
    sudo -u "$APP_USER" git checkout "$GIT_BRANCH" 2>/dev/null || warn "checkout atlandı (yerel değişiklikler olabilir)"
    sudo -u "$APP_USER" git pull --ff-only origin "$GIT_BRANCH"
    ok "Kod güncel: $(sudo -u "$APP_USER" git log -1 --oneline)"
else
    warn ".git bulunamadı — kod kopyalama yöntemiyle güncelliyorsanız dosyaları yükleyin."
fi

log "3/6 Bağımlılıklar"
if ! sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader --no-interaction --quiet; then
    warn "composer install başarısız (lock uyuşmazlığı olabilir) — composer update --no-dev deneniyor"
    sudo -u "$APP_USER" composer update --no-dev --optimize-autoloader --no-interaction --quiet \
        || die "Bağımlılıklar kurulamadı: sudo -u $APP_USER composer install --no-dev -vvv"
fi
ok "composer install tamam"

log "4/6 Veritabanı ve sembolik bağlantı"
sudo -u "$APP_USER" "$PHP_BIN" artisan migrate --force
sudo -u "$APP_USER" "$PHP_BIN" artisan storage:link 2>/dev/null || true
if grep -q '^ADMIN_EMAIL=..*' .env; then
    sudo -u "$APP_USER" "$PHP_BIN" artisan bayiinet:ensure-admin || true
fi
ok "migration tamam"

log "5/6 İzinler ve önbellek"
chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
sudo -u "$APP_USER" "$PHP_BIN" artisan config:cache
sudo -u "$APP_USER" "$PHP_BIN" artisan route:cache || warn "route:cache atlandı"
sudo -u "$APP_USER" "$PHP_BIN" artisan view:cache || true
sudo -u "$APP_USER" "$PHP_BIN" artisan event:cache || true
ok "önbellekler yenilendi"

log "6/6 Servisler yeniden başlatılıyor"
systemctl reload "php${PHP_VER}-fpm" 2>/dev/null || systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || warn "php-fpm yenilenemedi"
sudo -u "$APP_USER" "$PHP_BIN" artisan queue:restart || warn "queue:restart atlandı (işçi tamamlanan işten sonra kendini yeniler)"
if [[ -d /etc/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
    for UNIT in bayiinet-queue bayiinet-maintenance bayiinet-schedule; do
        if [[ -f "deploy/oracle/${UNIT}.service" ]]; then
            sed "s|__APP_DIR__|$APP_DIR|g; s|__USER__|$APP_USER|g; s|__PHP__|$PHP_BIN|g" \
                "deploy/oracle/${UNIT}.service" > "/etc/systemd/system/${UNIT}.service"
        fi
    done
    systemctl daemon-reload 2>/dev/null || true
    systemctl restart bayiinet-queue bayiinet-maintenance bayiinet-schedule 2>/dev/null || warn "systemd servisleri yenilenemedi (systemd kullanmıyorsanız normaldir)"
fi
systemctl reload nginx 2>/dev/null || true
ok "servisler yenilendi"

sudo -u "$APP_USER" "$PHP_BIN" artisan up || true

echo
sudo -u "$APP_USER" "$PHP_BIN" artisan bayiinet:teshis || true
