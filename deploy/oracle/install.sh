#!/usr/bin/env bash
#
# Bayiinet — Oracle Cloud / Ubuntu tek komut kurulum betiği
#
# Ne yapar?
#   1) nginx, php8.3-fpm (+ gerekli uzantılar), composer, unzip, git, cron kurar
#   2) Proje dizinini hazırlar: izinler, storage klasörleri, .env, APP_KEY
#   3) Veritabanı migration'ları, storage:link, yönetici hesabı
#   4) nginx site dosyası + php-fpm ayarları (256M upload / 512M memory)
#   5) systemd birimleri: bayiinet-queue (kuyruk işçisi) + bayiinet-schedule (zamanlayıcı)
#   6) Oracle Cloud için iptables/ufw port kuralları (80/443)
#   7) Sonunda teşhis çalıştırır: php artisan bayiinet:teshis
#
# Kullanım:
#   cd /var/www/bayiinet
#   sudo bash deploy/oracle/install.sh
#
# Seçenekler (ortam değişkeni):
#   APP_DIR=/var/www/bayiinet   Proje dizini
#   APP_USER=www-data           Uygulamayı çalıştıran kullanıcı
#   DOMAIN=bayiinet.com         nginx server_name (boşlukla birden fazla yazılabilir)
#   INSTALL_MYSQL=1             Aynı sunucuya MySQL/MariaDB de kurulsun
#   SKIP_NGINX=1                nginx yapılandırmasını atla
#   SKIP_FIREWALL=1             iptables/ufw kurallarını atla
#
# Not: Betik idempotenttir; hata alırsanız düzeltip tekrar çalıştırabilirsiniz.

set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
APP_USER="${APP_USER:-www-data}"
DOMAIN="${DOMAIN:-_}"
INSTALL_MYSQL="${INSTALL_MYSQL:-0}"
SKIP_NGINX="${SKIP_NGINX:-0}"
SKIP_FIREWALL="${SKIP_FIREWALL:-0}"
PHP_VER="8.3"
PHP_BIN=""   # 1. adımda doldurulur (systemd birimlerine yazılır)

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mHATA: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Bu betiği sudo ile çalıştırın: sudo bash deploy/oracle/install.sh"
[[ -f "$APP_DIR/artisan" ]] || die "$APP_DIR bir Laravel projesi değil (artisan bulunamadı). APP_DIR değişkeniyle dizini belirtin."

log "Bayiinet kurulumu başlıyor"
echo "  Proje dizini : $APP_DIR"
echo "  Kullanıcı    : $APP_USER"
echo "  Alan adı     : $DOMAIN"

# ---------------------------------------------------------------- 1) Paketler
log "1/8 Sistem paketleri"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

PKGS=(
    nginx git unzip curl ca-certificates
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql"
    "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-curl"
    "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" "php${PHP_VER}-gd"
    "php${PHP_VER}-intl" "php${PHP_VER}-sqlite3"
)
if [[ "$INSTALL_MYSQL" == "1" ]]; then
    PKGS+=( mysql-server )
fi
apt-get install -y -qq "${PKGS[@]}" >/dev/null
php -v | head -1
# Sunucuda hem php8.3 hem php olabilir; systemd birimlerine gerçek yolu yazıyoruz.
PHP_BIN="$(command -v "php${PHP_VER}" || command -v php)"
[[ -n "$PHP_BIN" ]] || die "PHP çalıştırılabilir dosyası bulunamadı."
ok "PHP ${PHP_VER} ve nginx kuruldu ($PHP_BIN)"

if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi
ok "Composer: $(composer --version --no-ansi | head -1)"

# ---------------------------------------------------------------- 2) Proje
log "2/8 Proje dizini ve izinler"
cd "$APP_DIR"

mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
chmod -R 775 storage bootstrap/cache
ok "storage/bootstrap/cache izinleri verildi"

if [[ ! -f .env ]]; then
    cp .env.example .env
    warn ".env oluşturuldu — DB_* ve ADMIN_* değerlerini SİZ doldurmalısınız!"
fi

# ÖNEMLİ SIRA: composer install, `php artisan` çağrılarından ÖNCE gelmeli.
# vendor/ yoksa artisan "Failed opening required vendor/autoload.php" hatasıyla
# ölür ve `set -e` yüzünden kurulum burada dururdu.
log "Composer bağımlılıkları (üretim)"
if ! sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader --no-interaction --quiet; then
    warn "composer install başarısız — composer.lock composer.json ile uyuşmuyor olabilir."
    warn "Deneniyor: composer update --no-dev (lock dosyası sunucuda yenilenecek)"
    sudo -u "$APP_USER" composer update --no-dev --optimize-autoloader --no-interaction --quiet \
        || die "Composer bağımlılıkları kurulamadı. Ayrıntı için: sudo -u $APP_USER composer install --no-dev -vvv"
fi
ok "vendor/ hazır"

if ! grep -q '^APP_KEY=base64:' .env; then
    sudo -u "$APP_USER" "$PHP_BIN" artisan key:generate --force
fi
ok "APP_KEY hazır"

# ---------------------------------------------------------------- 3) PHP ayarları
log "3/8 PHP ayarları (upload 256M · memory 512M · max_execution_time 300)"
for SAPI in fpm cli; do
    if [[ -d "/etc/php/${PHP_VER}/${SAPI}/conf.d" ]]; then
        cp deploy/oracle/php-fpm-bayiinet.ini "/etc/php/${PHP_VER}/${SAPI}/conf.d/99-bayiinet.ini"
    fi
done
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
ok "PHP ayarları yazıldı"

# ---------------------------------------------------------------- 4) Veritabanı
log "4/8 Veritabanı hazırlığı ve migration"
warn "Şimdi migration çalıştırılacak. .env içindeki DB_* değerleri doğru değilse"
warn "bu adım başarısız olur — .env'i düzenleyip betiği tekrar çalıştırabilirsiniz."
sudo -u "$APP_USER" "$PHP_BIN" artisan migrate --force || warn "Migration başarısız (yukarıdaki hatayı giderin)"
sudo -u "$APP_USER" "$PHP_BIN" artisan storage:link 2>/dev/null || true
if grep -q '^ADMIN_EMAIL=..*' .env && grep -q '^ADMIN_PASSWORD=..*' .env; then
    sudo -u "$APP_USER" "$PHP_BIN" artisan bayiinet:ensure-admin --force || true
else
    warn "ADMIN_EMAIL / ADMIN_PASSWORD .env içinde boş — yönetici hesabı oluşturulmadı."
fi
ok "Veritabanı adımı tamam"

# ---------------------------------------------------------------- 5) nginx
log "5/8 nginx site yapılandırması"
if [[ "$SKIP_NGINX" != "1" ]]; then
    sed "s|__APP_DIR__|$APP_DIR|g; s|__DOMAIN__|$DOMAIN|g" \
        deploy/oracle/nginx-bayiinet.conf > /etc/nginx/sites-available/bayiinet
    ln -sf /etc/nginx/sites-available/bayiinet /etc/nginx/sites-enabled/bayiinet
    if [[ -e /etc/nginx/sites-enabled/default ]]; then
        rm -f /etc/nginx/sites-enabled/default
    fi
    nginx -t
    systemctl enable --now nginx >/dev/null 2>&1
    systemctl reload nginx
    ok "nginx yapılandırıldı (root: $APP_DIR/public)"
else
    warn "nginx adımı atlandı (SKIP_NGINX=1)"
fi

# Apache kullananlar için: deploy/oracle/apache-bayiinet.conf dosyasına bakın.

# ---------------------------------------------------------------- 6) systemd
log "6/8 Kuyruk işçisi ve zamanlayıcı servisleri"
for UNIT in bayiinet-queue bayiinet-maintenance bayiinet-schedule; do
    sed "s|__APP_DIR__|$APP_DIR|g; s|__USER__|$APP_USER|g; s|__PHP__|$PHP_BIN|g" \
        "deploy/oracle/${UNIT}.service" > "/etc/systemd/system/${UNIT}.service"
done
systemctl daemon-reload
systemctl enable --now bayiinet-queue bayiinet-maintenance bayiinet-schedule >/dev/null 2>&1
sleep 2
ok "bayiinet-queue, bayiinet-maintenance ve bayiinet-schedule başlatıldı"

# ---------------------------------------------------------------- 7) Güvenlik duvarı
log "7/8 Güvenlik duvarı (Oracle Cloud: VCN + iptables + ufw)"
if [[ "$SKIP_FIREWALL" != "1" ]]; then
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi active; then
        ufw allow 80/tcp >/dev/null 2>&1 || true
        ufw allow 443/tcp >/dev/null 2>&1 || true
        ok "ufw: 80/443 açıldı"
    fi
    # Oracle Cloud Ubuntu imajları iptables ile 80/443'ü varsayılan kapatır:
    # VCN security list'te açık olsa bile sunucu içinde kural yoksa site açılmaz.
    if command -v iptables >/dev/null 2>&1; then
        for PORT in 80 443; do
            if ! iptables -C INPUT -p tcp --dport "$PORT" -j ACCEPT 2>/dev/null; then
                iptables -I INPUT 6 -m state --state NEW -p tcp --dport "$PORT" -j ACCEPT || true
                ok "iptables: $PORT açıldı"
            fi
        done
        command -v netfilter-persistent >/dev/null 2>&1 && netfilter-persistent save >/dev/null 2>&1 || true
    fi
    warn "Oracle Cloud konsolunda VCN → Security List → Ingress Rules: 0.0.0.0/0 → TCP 80 ve 443 ekli olmalı!"
else
    warn "Güvenlik duvarı adımı atlandı (SKIP_FIREWALL=1)"
fi

# ---------------------------------------------------------------- 8) Önbellek + teşhis
log "8/8 Önbellek ve teşhis"
sudo -u "$APP_USER" "$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan route:clear >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan view:clear >/dev/null 2>&1 || true
sudo -u "$APP_USER" "$PHP_BIN" artisan config:cache >/dev/null 2>&1 || warn "config:cache atlandı"
sudo -u "$APP_USER" "$PHP_BIN" artisan route:cache >/dev/null 2>&1 || warn "route:cache atlandı (kapatılmış rota olabilir)"
sudo -u "$APP_USER" "$PHP_BIN" artisan view:cache >/dev/null 2>&1 || true

echo
sudo -u "$APP_USER" "$PHP_BIN" artisan bayiinet:teshis || true

cat <<EOF

────────────────────────────────────────────────────────────────
Kurulum bitti. Sıradaki adımlar:

 1) .env dosyasını düzenleyin (zorunlu alanlar):
      sudo nano $APP_DIR/.env
        APP_URL=https://www.bayiinet.com
        DB_CONNECTION=mysql  DB_HOST=127.0.0.1  DB_DATABASE=bayiinet
        DB_USERNAME=bayiinet DB_PASSWORD=...
        ADMIN_EMAIL=...      ADMIN_PASSWORD=...   (ilk giriş)
      (HTTPS kurduktan sonra: SESSION_SECURE_COOKIE=true)

 2) .env değiştiyse:
      cd $APP_DIR && sudo -u $APP_USER php artisan config:cache
      sudo systemctl restart bayiinet-queue bayiinet-maintenance bayiinet-schedule php${PHP_VER}-fpm

 3) HTTPS (önerilir):
      sudo apt install certbot python3-certbot-nginx
      sudo certbot --nginx -d bayiinet.com -d www.bayiinet.com

 4) Servis durumları:
      systemctl status bayiinet-queue bayiinet-maintenance bayiinet-schedule nginx php${PHP_VER}-fpm
      tail -f $APP_DIR/storage/logs/queue-worker.log

 5) Teşhis (her sorunda ilk çalıştırılacak komut):
      cd $APP_DIR && sudo -u $APP_USER php artisan bayiinet:teshis --api

 6) Kod güncellemesinden sonra:
      cd $APP_DIR && sudo bash deploy/oracle/deploy.sh
────────────────────────────────────────────────────────────────
EOF
