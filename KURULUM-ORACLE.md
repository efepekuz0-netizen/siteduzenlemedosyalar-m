# Bayiinet — Oracle Cloud (Ubuntu) Kurulum ve Sorun Giderme

Bu belge, uygulamayı **Render yerine kendi sunucuda (Oracle Cloud / Ubuntu)**
çalıştırmak için gereken her adımı ve sunucuda en sık alınan hataların
çözümünü içerir.

> **Özet:** Kendi sunucuda en kritik fark, **kuyruk işçisinin** ve
> **zamanlayıcının** artık bir platform tarafından otomatik çalıştırılmamasıdır.
> Bu iki süreç çalışmazsa uygulama açılır ama **XML içe aktarma, Trendyol ürün
> gönderimi ve fiyat/stok eşitleme hiç ilerlemez.** Aşağıdaki 3.3 adımı
> (`install.sh`) bu süreçleri systemd servisi olarak kurar.

---

## 1. Gereksinimler

| Bileşen | Sürüm / Not |
|---|---|
| Ubuntu | 22.04 veya 24.04 (Oracle Cloud imajları uygun) |
| PHP | **8.3+** (`php8.3-fpm`, `php8.3-cli`, `php8.3-mysql`, `mbstring`, `xml`, `curl`, `zip`) |
| MySQL/MariaDB | 8.0+ / 10.4+ (Oracle Cloud'da ayrı bir MySQL HeatWave de kullanılabilir) |
| nginx | 1.18+ (paket sürümü yeterli) |
| Composer | 2.x |
| Disk | en az 5 GB boş (log + XML dosyaları için 512 MB'dan fazlası önerilir) |
| RAM | 1 GB yeterli; 2 GB rahat (kuyruk işçisi `memory_limit=512M` ile çalışır) |

Uygulama tarafı: Laravel 13 / PHP `^8.3` (bkz. `composer.json`).

---

## 2. Neden Render'da çalışıyordu da sunucuda hata alıyorum?

| Render (önceki) | Kendi sunucu (Oracle) | Sonuç |
|---|---|---|
| `Dockerfile` + `docker/start.sh` web sunucusu kurar | nginx + php-fpm | `docker/start.sh` **çalıştırılmaz**, `php artisan serve` kullanılmaz |
| `PORT=10000` dinleyen süreç | 80/443 nginx | Uygulama 80'de dinlemez; sorun nginx yapılandırmasındadır |
| Background Worker servisi (ayrı) | **systemd `bayiinet-queue`** | İşçi yoksa gönderim/içe aktarma `jobs` tablosunda bekler |
| Cron/Scheduler servisi | **systemd `bayiinet-schedule`** | Saatlik senkron ve 15 dk'lık batch kontrolü durur |
| `LOG_CHANNEL=stderr` (platform logu) | `daily` dosya logu | Loglar `storage/logs/laravel.log` içine yazılır |
| Ortam değişkenleri panelden | **`.env` dosyası** | `config:cache` sonrası `.env` değişikliği için cache yenilenmelidir |
| HTTPS otomatik | certbot ile kurulmalı | HTTPS yoksa `SESSION_SECURE_COOKIE=true` girişi bozar |
| `QUEUE_INLINE_DRAIN=true` (işçi hilesi) | **`false` olmalı** | Kendi sunucuda gerçek işçi vardır; hile çift işçilik yapar |

---

## 3. Adım adım kurulum

### 3.1 Kodu sunucuya alın

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/efepekuz0-netizen/siteduzenlemedosyalar-m bayiinet
cd /var/www/bayiinet
# Eski sunucudan dosya kopyalıyorsanız .env ve storage/app dosyalarını da alın.
sudo git config --system --add safe.directory /var/www/bayiinet   # "dubious ownership" hatasını önler
```

### 3.2 Veritabanını oluşturun

```bash
sudo mysql
```

```sql
CREATE DATABASE bayiinet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bayiinet'@'localhost' IDENTIFIED BY 'GUCLU-BIR-SIFRE';
GRANT ALL PRIVILEGES ON bayiinet.* TO 'bayiinet'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3.3 Kurulum betiğini çalıştırın

Betik şunları yapar: sistem paketleri → php-fpm ayarları (256M upload / 512M
memory) → `.env` → `composer install` → `migrate` → nginx sitesi →
**systemd kuyruk işçisi + ağır işler işçisi + zamanlayıcı** → güvenlik duvarı →
`bayiinet:teshis` raporu.

```bash
cd /var/www/bayiinet
sudo DOMAIN="bayiinet.com www.bayiinet.com" bash deploy/oracle/install.sh
```

> Alan adı henüz yoksa `DOMAIN="_"` bırakabilirsiniz (IP ile açılır).

**Önemli:** İlk çalıştırmada `.env` boş olduğu için migration başarısız olabilir.
Betik bunu uyarı olarak geçer; `.env`'i doldurup **tekrar çalıştırın**
(betik idempotenttir).

### 3.4 `.env` dosyasını doldurun

```bash
sudo -u www-data nano /var/www/bayiinet/.env
```

Zorunlu alanlar:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.bayiinet.com
APP_KEY=base64:...            # install.sh üretir (boşsa: php artisan key:generate)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bayiinet
DB_USERNAME=bayiinet
DB_PASSWORD=...
DB_QUEUE_RETRY_AFTER=960      # job zaman aşımı (en fazla 840 sn) < retry_after olmalı

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true    # HTTPS kurulduktan SONRA true; HTTP'de false bırakın
CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=daily
LOG_DAILY_DAYS=14

ADMIN_EMAIL=eposta@ornek.com  # ilk kurulumda yönetici hesabı
ADMIN_PASSWORD=...            # ilk girişten sonra panelden değiştirin

QUEUE_INLINE_DRAIN=false      # kendi sunucuda MUTLAKA false

# Trendyol (isteğe bağlı, panelden de girilebilir)
TRENDYOL_FALLBACK_BRAND_ID=2613880
TRENDYOL_COMMISSION_RATE=15
TRENDYOL_MIN_PROFIT=10
TRENDYOL_ROUND_TO=0.99
HEALTH_DETAIL_TOKEN=          # boşsa /health-detail yalnız sunucudan erişilir
```

`.env` değiştiyse:

```bash
cd /var/www/bayiinet
sudo -u www-data php artisan config:cache
sudo systemctl restart bayiinet-queue bayiinet-maintenance bayiinet-schedule php8.3-fpm
```

### 3.5 HTTPS (önerilir, hatta şart)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d bayiinet.com -d www.bayiinet.com
```

Ardından `.env` içinde `APP_URL=https://...` ve `SESSION_SECURE_COOKIE=true`
yapıp `config:cache` çalıştırın. **HTTPS yokken `SESSION_SECURE_COOKIE=true`
bırakmak "giriş yapılıyor ama ana sayfaya dönüyor" hatasının bir numaralı
sebebidir.**

### 3.6 Oracle Cloud ağ + güvenlik duvarı

1. **VCN → Security List → Ingress Rules:** `0.0.0.0/0` → TCP **80** ve **443**
   (Oracle imajları varsayılan olarak yalnız 22'yi açar).
2. Ubuntu imajlarında **iptables** de 80/443'ü kapatır (install.sh açar;
   manuel: `sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT`).
3. `ufw` kullanıyorsanız: `sudo ufw allow 80,443/tcp`.

### 3.7 Kontrol

```bash
curl -I http://127.0.0.1            # nginx cevap veriyor mu
curl -s http://127.0.0.1/up         # Laravel sağlık uç noktası
curl -s http://127.0.0.1/health-detail   # teşhis (yalnız sunucudan; token ile dışarıdan)
systemctl status nginx php8.3-fpm bayiinet-queue bayiinet-maintenance bayiinet-schedule
```

---

## 4. Servis mimarisi (ne çalışmalı?)

| Servis | Olmazsa ne olur? | Durum komutu |
|---|---|---|
| **nginx** | Site hiç açılmaz | `systemctl status nginx` |
| **php8.3-fpm** | **502 Bad Gateway** | `systemctl status php8.3-fpm` |
| **bayiinet-queue** | Trendyol gönderimi, batch doğrulama, panel işleri **hiç ilerlemez** | `systemctl status bayiinet-queue` |
| **bayiinet-maintenance** | XML içe aktarma (15.000 ürün) ve toplu fiyat güncellemesi gecikir | `systemctl status bayiinet-maintenance` |
| **bayiinet-schedule** | Saatlik XML/Trendyol senkronu ve 15 dk'lık batch kontrolü çalışmaz | `systemctl status bayiinet-schedule` |
| **MySQL/MariaDB** | 500 hatası / bağlantı hatası | `systemctl status mysql` |

**Kuyruk ayrımı (bu sürümde netleşti):**

| Kuyruk | İçerik | Tüketen servis |
|---|---|---|
| `marketplace` | Trendyol ürün gönderimi, batch doğrulama, ilan silme | `bayiinet-queue` (öncelikli) |
| `default` | Panelden tetiklenen kısa işler | `bayiinet-queue` |
| `maintenance` | XML içe aktarma, toplu fiyat, katalog/stok senkronu (840 sn'ye kadar) | `bayiinet-maintenance` (öncelikli) + `bayiinet-queue` (en son sırada) |

Eskiden ağır işler de `default` kuyruğundaydı ve tek işçi sırayla çalıştırdığı
için **XML içe aktarma sırasında Trendyol gönderimi dakikalarca bekliyordu.**

Yararlı komutlar:

```bash
sudo systemctl restart bayiinet-queue          # Trendyol/panel işçisi
sudo systemctl restart bayiinet-maintenance    # XML + toplu fiyat işçisi
sudo journalctl -u bayiinet-queue -n 100 -f    # systemd logu
tail -f /var/www/bayiinet/storage/logs/queue-worker.log
tail -f /var/www/bayiinet/storage/logs/maintenance-worker.log
tail -f /var/www/bayiinet/storage/logs/laravel.log

# Kuyrukta ne var?
sudo -u www-data php artisan queue:monitor default:100,marketplace:100,maintenance:50
sudo -u www-data php artisan queue:failed          # başarısız işler
sudo -u www-data php artisan queue:retry all       # hepsini yeniden dene
```

---

## 5. Sık alınan hatalar → sebep → çözüm

| Hata / belirti | Sebep | Çözüm |
|---|---|---|
| **502 Bad Gateway** | php-fpm çalışmıyor veya nginx'teki socket yolu farklı (php8.2 vs 8.3) | `systemctl status php8.3-fpm`; nginx'te `fastcgi_pass unix:/run/php/php8.3-fpm.sock;` satırı kurulu sürümle aynı olmalı |
| `could not find driver` (500) | `php8.3-mysql` kurulu değil | `sudo apt install php8.3-mysql && sudo systemctl restart php8.3-fpm` |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL kapalı / yanlış host | `systemctl status mysql`; `.env` `DB_HOST=127.0.0.1` (soket kullanıyorsa `localhost`) |
| `SQLSTATE[HY000] [1045] Access denied` | Kullanıcı/şifre hatası | 3.2 adımındaki `CREATE USER`/`GRANT` komutlarını tekrarlayın |
| `Base table or view not found: ...sessions/cache/jobs` | Migration çalışmamış | `sudo -u www-data php artisan migrate --force` |
| `No application encryption key has been specified` | `APP_KEY` boş | `sudo -u www-data php artisan key:generate --force` sonra `config:cache` |
| `The stream or file ".../laravel.log" could not be opened` | storage izinleri | `sudo chown -R www-data:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache` |
| Beyaz sayfa / 403 | nginx `root` yanlış (proje kökü) | `root /var/www/bayiinet/public;` olmalı |
| Giriş yapılıyor ama tekrar giriş ekranı | `SESSION_SECURE_COOKIE=true` + HTTP | HTTPS kurun ya da `.env`'de `false` yapıp `config:cache` |
| `dubious ownership` (git pull) | Git güvenlik kontrolü | `git config --system --add safe.directory /var/www/bayiinet` |
| `composer install` → `Your lock file does not contain a compatible set of packages` | `composer.lock` ile `composer.json` uyuşmuyor | `sudo -u www-data composer update --no-dev --optimize-autoloader` (install.sh/deploy.sh bunu otomatik dener) |
| `composer install` bellek hatası | küçük VM | `COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev` |
| `php: command not found` | `/usr/bin/php` yok, yalnız `/usr/bin/php8.3` var | `php8.3 artisan ...`; systemd birimleri `install.sh`/`deploy.sh` tarafından doğru yolla yazılır (`__PHP__` yer tutucusu) |
| `Unable to prepare route [/...] for serialization. Uses Closure.` | `route:cache` Closure rota ile çalışmaz | Güncel kodda `/ping`, `/health-detail`, `/basvuru-durumu` `SystemController`'a taşındı; `php artisan route:cache` artık sorunsuz |
| Panelde **"Gönderilebilir ürün: 0"** ama ürün var | MySQL'de `images` **JSON** kolonu eski kodda `images = ''` ile karşılaştırılıyordu → `ERROR 3141 Invalid JSON text: "The document is empty."` | Güncel kodda tip bağımsız: `TRIM(CAST(images AS CHAR)) IN ('', '[]', 'null')`. Logda 3141 görürseniz kodu güncelleyin |
| Gönderim başlıyor, ilerleme `0 / N` kalıyor | Kuyruk işçisi yok | `sudo systemctl start bayiinet-queue`; bekleyen işler otomatik işlenir |
| Trendyol gönderimi XML içe aktarma sırasında bekliyor | Ağır işler tek işçiyi meşgul ediyordu | `maintenance` kuyruğu + `bayiinet-maintenance` servisi (bkz. 4. bölüm) |
| Panelde "Zamanlayıcı sinyal yok" | `bayiinet-schedule` durmuş | `sudo systemctl start bayiinet-schedule` |
| Trendyol'da "0 gönderildi" / sayaç uyuşmuyor | Gönderilen = yalnız Trendyol'da **oluşan** kalemler; yeni iletilenler batch doğrulaması sonrası sayılır | Panelde **batch sonuçları** tablosunu ve "Gönderildi olanları kontrol et" düğmesini kullanın |
| Ürünler "görsel yok" diye reddediliyor | XML'de `Image` alanı boş | Kaynağı düzeltin; paneldeki **Gönderim öncesi kontrol** kartı kaç ürünün eksik olduğunu gösterir |
| `Malformed UTF-8 characters` (içe aktarma) | Bozuk kodlamalı tedarikçi XML'i | Güncel kodda `JSON_INVALID_UTF8_SUBSTITUTE` ile geçilir; kaynağı UTF-8'e çevirmek yine de en iyisidir |
| `Allowed memory size ... exhausted` | PHP memory 128 MB | `deploy/oracle/php-fpm-bayiinet.ini` dosyasını `/etc/php/8.3/{fpm,cli}/conf.d/` altına kopyalayın (`memory_limit=512M`) |
| 504 Gateway Timeout | `max_execution_time=30` | Aynı ini dosyası (`300`); ağır işler zaten kuyrukta çalışır |
| `413 Request Entity Too Large` / XML yüklenmiyor | nginx/php upload limiti | `client_max_body_size 256M;` (nginx şablonunda) + `upload_max_filesize=256M` (ini dosyası) |
| `SQLSTATE ... Unknown column` | Eksik migration/kolon | `php artisan migrate --force`; `bayiinet:teshis` eksik kolonları listeler |
| Trendyol API `401 Unauthorized` | Seller ID / API Key / Secret hatalı | Panelden **Pazaryeri** veya bayi Trendyol ekranından bilgileri kaydedip **Bağlantıyı test et** |
| Trendyol `429 Too Many Requests` | Kısa sürede çok istek | `TRENDYOL_RETRY_TIMES=3`, `TRENDYOL_RETRY_SLEEP=1500`; gönderimi 100'lük paketlerle yapın |
| `/health-detail` 404 dönüyor | Bilinçli: uç nokta yalnızca sunucudan veya `HEALTH_DETAIL_TOKEN` ile erişilebilir | `curl http://127.0.0.1/health-detail` ya da `.env`'e token ekleyip `?token=...` |
| Site dışarıdan açılmıyor, sunucudan açılıyor | VCN Security List / iptables | 3.6 adımı |
| Kod değişti ama site eskisi gibi | OPcache (`opcache.validate_timestamps=0`) + config cache | `sudo bash deploy/oracle/deploy.sh` (php-fpm'i yeniler, cache'i kurar) |
| `queue:work` çalışıyor ama iş bitmiyor | Job `$timeout` > kuyruk `retry_after` → iş ikinci kez dağıtılır | `$timeout` en fazla 840 sn, `DB_QUEUE_RETRY_AFTER=960`, işçi `--timeout=900` |

Hatasi ne olursa olsun **ilk çalıştırılacak komut**:

```bash
cd /var/www/bayiinet && sudo -u www-data php artisan bayiinet:teshis --api
```

Bu komut sırayla kontrol eder: PHP sürümü ve **gerekli uzantılar**, `APP_KEY`,
`APP_ENV/APP_DEBUG`, `APP_URL`, oturum çerezi uyumu, storage izinleri,
`storage:link`, **veritabanı bağlantısı**, **kuyruk/önbellek sürücüsü**, disk
alanı, **eksik tablo/kolonlar**, bekleyen migration'lar, kuyruk ve zamanlayıcı
nabzı, bekleyen/başarısız işler, her bayinin Trendyol kimlikleri, API
bağlantısı, ilan durumları ve **kuru çalıştırma** (hangi ürün neden
gönderilemez).

---

## 6. Günlük kullanım: panelden kontroller

| Ekran | Ne işe yarar |
|---|---|
| **Sistem Sağlığı** (`/admin/sistem-sagligi`) | Kuyruk işçisi çalışıyor mu, son nabzı, en eski bekleyen işin yaşı, başarısız işler, eksik kolon/tablo |
| **Otomasyon** (`/admin/otomasyon`) | Zamanlayıcı ve işçi durumu, her görevin son çalışması |
| **Bayi → Trendyol** (`/admin/bayiler/{id}/trendyol`) | **Gönderim öncesi kontrol** (görselsiz/barkodsuz/fiyatsız sayıları), **kuru çalıştırma**, batch sonuçları, reddedilme gerekçeleri, fiyat/stok eşitleme |
| **Pazaryeri** (`/admin/pazaryeri`) | Trendyol API bilgileri, katalog senkronu, sipariş çekme |

### Trendyol gönderim akışı (önerilen sıra)

1. **Kaynaklar** → XML kaynağına **varsayılan Trendyol Marka No** ve (varsa) kategori no girin.
2. **Ürünler → XML'i şimdi çek** → içe aktarma `maintenance` kuyruğunda çalışır (işçi şart!).
3. **Bayiler → bayi → Trendyol** → API bilgilerini kaydedin, **Bağlantıyı test edin**.
4. **Gönderim öncesi kontrol** kartındaki eksikleri giderin.
5. **Seçilenleri kontrol et (kuru)** → Trendyol'a istek gitmeden her kalemin durumu ve engel gerekçesi görünür.
6. **Tüm ürünleri gönder** → ilerleme 15 saniyede bir yenilenir.
7. Sonuç bekleyenler için **"Gönderildi olanları kontrol et"** (15 dakikada bir otomatik de çalışır).
8. Fiyat/stok değiştiyse **Fiyat ve stokları şimdi eşitle**.

**Sayaçlar nasıl okunur?**

- **Gönderilen**: yalnızca Trendyol'da **oluşan** kalemler (batch doğrulaması sonrası).
- **Doğrulama bekleyen**: Trendyol'a iletilmiş, sonucu henüz gelmemiş kalemler.
- **Reddedilen**: Trendyol'un gerekçesiyle kabul etmediği kalemler (gerekçe tabloda görünür).
- **Atlanan**: barkod/fiyat/görsel eksikliğinden hiç gönderilemeyen kalemler.

---

## 7. Render'dan geçiş notları

- `Dockerfile` ve `docker/start.sh` **yalnızca konteyner/Render içindir**;
  Oracle'da nginx + php-fpm + systemd kullanılır. Dosyalar silinmedi (Render'a
  dönmek gerekirse çalışır durumda).
- Oracle'da `php artisan serve` **kullanılmaz**; 80/443 nginx'e aittir.
- `QUEUE_INLINE_DRAIN=false` bırakın. `true` yalnızca ayrı işçi süreci
  olmayan platformlar içindir (istek sonunda kuyruğu tüketir).
- `PORT` değişkeni gerekmez; `.env`'de `APP_URL` gerçek alan adı olmalıdır.
- Log takibi: Render'da panel logu → burada `storage/logs/laravel.log`.

---

## 8. Güncelleme ve yedekleme

```bash
# Kod güncellemesi (git pull + composer + migrate + cache + servis yenileme)
cd /var/www/bayiinet
sudo bash deploy/oracle/deploy.sh

# Yedek (veritabanı + storage) — her gün 03:15'te cron ile
sudo crontab -e
15 3 * * * cd /var/www/bayiinet && APP_DIR=/var/www/bayiinet BACKUP_DIR=/var/backups/bayiinet bash deploy/oracle/backup.sh >> /var/log/bayiinet-backup.log 2>&1
```

`deploy.sh` sonunda otomatik olarak `bayiinet:teshis` çalışır ve raporu yazar.

---

## 9. Güvenlik kontrol listesi

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` üretildi (asla `git`'e girmiyor)
- [ ] HTTPS kuruldu, `SESSION_SECURE_COOKIE=true`
- [ ] `.env` izinleri: `sudo chown www-data:www-data .env && sudo chmod 600 .env`
- [ ] `ADMIN_PASSWORD` ilk girişten sonra panelden değiştirildi
- [ ] MySQL yalnızca `localhost`'tan erişilebilir (Oracle Cloud'da 3306 dışarı açık olmamalı)
- [ ] VCN Security List'te yalnızca 22 (kısıtlı IP), 80, 443 açık
- [ ] `storage/logs` ve yedekler disk izleme kapsamında
- [ ] `/health-detail` ya kapalı ya da `HEALTH_DETAIL_TOKEN` ile korumalı
