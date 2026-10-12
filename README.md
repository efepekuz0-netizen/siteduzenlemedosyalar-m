# Bayiinet — XML Bayilik & Toptan Platformu

Tedarikçi XML'lerini içe aktaran, bayilere özel XML feed sunan ve Trendyol'a
ürün gönderen Laravel uygulaması.

> Eski adı **BayiXML** idi; panel yolları da `/bayi/*` yerine artık kök
> yollarda (`/panel`, `/katalog`, `/hesabim`).

## Kurulum (üretim — Oracle Cloud / Ubuntu)

```bash
git clone <repo> /var/www/bayiinet && cd /var/www/bayiinet
sudo DOMAIN="bayiinet.com www.bayiinet.com" bash deploy/oracle/install.sh
```

Kurulum betiği nginx + php-fpm + **kuyruk işçisi** (`bayiinet-queue`) +
**zamanlayıcı** (`bayiinet-schedule`) servislerini kurar, izinleri ayarlar,
migration'ları çalıştırır ve teşhis raporu yazdırır.
Ayrıntılı adımlar, sık alınan hatalar ve çözümleri: **[KURULUM-ORACLE.md](KURULUM-ORACLE.md)**.

> Kendi sunucuda en kritik nokta: **`queue:work` süreci çalışmazsa Trendyol
> gönderimi ve XML içe aktarma hiç ilerlemez.** Durum panelde
> *Sistem Sağlığı* ve *Otomasyon* ekranlarında, komut satırında ise
> `php artisan bayiinet:teshis` ile görülür.

## Kurulum (yerel)

```bash
git clone <repo> bayiinet && cd bayiinet
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Geliştirme sunucusu **ve** kuyruk/zamanlayıcı birlikte çalışmalı:

```bash
php artisan serve                       # terminal 1
php artisan queue:work --queue=marketplace,default,maintenance   # terminal 2
php artisan schedule:work               # terminal 3 (saatlik görevler)
```

Aç: **http://127.0.0.1:8000** · Yönetim: **/admin** · Giriş: **/giris**

## Hesaplar

`php artisan migrate --seed` sonrası (`DatabaseSeeder`):

| Rol   | E-posta               | Şifre    |
|-------|-----------------------|----------|
| Admin | admin@bayiinet.test   | password |
| Bayi  | bayi@bayiinet.test    | password |

Üretimde hesap `ADMIN_EMAIL` / `ADMIN_PASSWORD` ortam değişkenleriyle,
`php artisan bayiinet:ensure-admin` komutuyla oluşturulur (demo hesap açılmaz).

## Ana hatlar

- **XML kaynakları** — dosya yükleme veya URL. İçe aktarma arka planda
  kuyrukta çalışır (`ImportSourceJob`), fiyatlar kaynağın kâr oranıyla toplu hesaplanır.
- **Bayi feed'i** — `/xml/{token}.xml` (bayiye özel fiyat + önerilen perakende),
  `/xml/feed.xml` (yönetici genel feed'i).
- **Trendyol entegrasyonu** — bayi bazlı API bilgileri; kategori/marka eşleştirme,
  toplu ürün gönderme, fiyat/stok eşitleme, batch sonucu sorgulama.
- **Otomasyon** — saatlik XML yenileme, saatlik Trendyol gönderimi, 15 dakikada bir
  batch sonucu kontrolü. Durum **Yönetim → Otomasyon** ekranından izlenir.

## Modüller

### Yönetim (`/admin`)
Dashboard · Siparişler · Müşteriler · XML Kaynakları · Ürünler · Kritik Stok ·
Bayiler (+ bayi bazlı Trendyol ekranı) · Kara Liste · Pazaryeri · İlanlar ·
Kategori & Marka · Kâr & Fiyatlama · Kayıtlar · **Otomasyon** · Ayarlar

### Bayi (`/panel`)
Dashboard + bakiye · Özel XML linki · Ürün kataloğu · Sipariş oluşturma
(bakiyeden düşer) · Sipariş geçmişi · Hesabım

## Önemli komutlar

| Komut | Açıklama |
|---|---|
| `php artisan bayiinet:teshis` | **Tam teşhis**: ortam, kuyruk/zamanlayıcı, Trendyol, şema |
| `php artisan bayiinet:teshis --api --kuru` | API bağlantı testi + gönderim öncesi kuru çalıştırma raporu |
| `php artisan bayiinet:ensure-admin` | Yönetici hesabını oluşturur / yükseltir |
| `php artisan bayiinet:sync-dealers` | Tüm aktif XML kaynaklarını yeniler |
| `php artisan bayiinet:sync-trendyol` | Bayilerin Trendyol senkronunu tetikler |
| `php artisan bayiinet:sync-trendyol --dealer=5` | Tek bayi |
| `php artisan queue:failed` / `queue:retry all` | Başarısız işleri listeler / yeniden dener |

Sunucuda süreçler:

```bash
sudo systemctl status|restart bayiinet-queue bayiinet-maintenance bayiinet-schedule
tail -f storage/logs/queue-worker.log
```

## Notlar

- Veritabanı: MySQL (yerelde SQLite da çalışır). **Kuyruk sürücüsü `database`**
  olduğu için `queue:work` süreci şarttır.
- Kuyruklar: `marketplace` (Trendyol gönderimi/doğrulama), `default` (panel),
  `maintenance` (XML içe aktarma + toplu fiyat). `bayiinet-queue` servisi
  `marketplace,default,maintenance` sırasıyla çalışır; `bayiinet-maintenance`
  yalnız ağır işleri tüketir.
- Üretimde `php artisan serve` yerine nginx/php-fpm veya FrankenPHP kullanın
  (bkz. `KURULUM.md`).
- Trendyol API anahtarları bayi kartında şifreli saklanır; `APP_KEY` sızarsa
  anahtarları yenileyin.
- Kod inceleme raporu ve düzeltilen maddeler: `INCELEME.md`.
