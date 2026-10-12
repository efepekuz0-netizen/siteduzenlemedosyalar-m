#!/bin/sh
# ─────────────────────────────────────────────────────────────
# BU DOSYA YALNIZCA KONTEYNER/Render ORTAMI İÇİNDİR.
#
# Oracle Cloud / kendi sunucu (Ubuntu) kurulumunda bu dosya kullanılmaz:
# nginx + php-fpm + systemd servisleri (bayiinet-queue, bayiinet-schedule)
# kullanılır. Ayrıntı: KURULUM-ORACLE.md ve deploy/oracle/install.sh
# ─────────────────────────────────────────────────────────────
cd /app || exit 1

# Büyük XML (izin yoksa sessizce atla)
mkdir -p /usr/local/etc/php/conf.d 2>/dev/null || true
cat > /usr/local/etc/php/conf.d/99-uploads.ini 2>/dev/null << 'INIEOF' || true
upload_max_filesize = 256M
post_max_size = 256M
memory_limit = 512M
max_execution_time = 300
INIEOF

if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan migrate --force || true
php artisan storage:link 2>/dev/null || true
php artisan bayiinet:ensure-admin || true

# Not: Eskiden burada `queue:flush` ve `queue:prune-failed --hours=0` vardı;
# her yeniden başlatmada bekleyen ve başarısız işleri siliyordu (hata ayıklama
# kaydı kayboluyordu). Artık kuyruğa dokunulmuyor.

# Kuyruk işçileri: Web konteynerinde de işçi çalışır (Oracle Cloud'da systemd
# servisleri kullanılır; ikisi birlikte çalışsa da zararsızdır).
#
# 1) Etkileşimli işçi: Trendyol gönderimi/doğrulaması önce gelir.
(
  while true; do
    php artisan queue:work database \
      --queue=marketplace,default,maintenance \
      --max-time=300 \
      --timeout=900 \
      --tries=3 \
      --memory=512 \
      --sleep=2 || true
    sleep 2
  done
) &

# 2) Ağır işler işçisi: XML içe aktarma ve toplu fiyat (840 sn'ye kadar sürer).
(
  while true; do
    php artisan queue:work database \
      --queue=maintenance \
      --max-time=3600 \
      --timeout=900 \
      --tries=1 \
      --memory=768 \
      --sleep=3 || true
    sleep 3
  done
) &

php artisan schedule:work &

export PHP_CLI_SERVER_WORKERS=1
exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}" --no-reload
