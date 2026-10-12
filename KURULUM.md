# Bayiinet — Render kurulumu

> ⚠ **Oracle Cloud / kendi sunucu (Ubuntu) kullanıyorsanız bu dosya sizi
> ilgilendirmiyor.** Bu belge Render (Docker) içindir. Kendi sunucu kurulumu,
> servis mimarisi ve karşılaşılan hataların çözümü için:
> **[KURULUM-ORACLE.md](KURULUM-ORACLE.md)** ve `deploy/oracle/install.sh`.

## 1. Dosyaları yükle
Bu depoyu GitHub'a yükleyin. `vendor/`, `.env` ve `database/*.sqlite` sürüm
 kontrolüne **dahil değildir** (`.gitignore`).

## 2. Servisler

Render'da **en az iki** servis oluşturun:

| Servis | Tür | Komut |
|---|---|---|
| Web | Web Service (Docker) | `sh docker/start.sh` (Dockerfile varsayılanı) |
| Worker | Background Worker (Docker) | `php artisan queue:work --queue=marketplace,default,maintenance --sleep=2 --tries=3 --timeout=900 --memory=512` |

> Web servisi de başlangıçta `queue:work` + `schedule:work` çalıştırır
> (`docker/start.sh`), ancak **ayrı bir worker servisi** yeniden başlatma ve
> dağıtımlarda işlerin kaybolmaması için önerilir. Zamanlayıcıyı iki yerde
> birden çalıştırmak sorun değildir: `withoutOverlapping` görevin çift
> çalışmasını engeller.

`php artisan serve` üretim için uygun değildir; mümkünse nginx/php-fpm veya
FrankenPHP + Octane kullanın.

## 3. Environment (ortam değişkenleri)

| Anahtar | Değer |
|---|---|
| `APP_KEY` | `base64:` ile başlayan 32 baytlık anahtar (aşağıya bakın) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://www.bayiinet.com` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | MySQL sunucu adresi |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | veritabanı adı |
| `DB_USERNAME` | kullanıcı adı |
| `DB_PASSWORD` | şifre |
| `ADMIN_EMAIL` | yönetici e-posta adresiniz |
| `ADMIN_PASSWORD` | en az 8 karakterli güçlü şifre |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS arkasında) |
| `AUTO_XML_SYNC` | saatlik XML senkronu — varsayılan `true` |
| `AUTO_TRENDYOL_SYNC` | saatlik Trendyol senkronu — varsayılan `true` |
| `TRENDYOL_BATCH_SIZE` | batch başına ürün — varsayılan `40` |
| `TRENDYOL_FALLBACK_BRAND_ID` | marka bulunamazsa kullanılacak Trendyol marka ID — varsayılan `2613880` |

APP_KEY üretmek için (PowerShell):

    "base64:" + [Convert]::ToBase64String((1..32 | % { Get-Random -Maximum 256 }))

Uzak MySQL sunucunuz Render'ın IP'lerine bağlantı izni vermelidir.

## 4. İlk açılış

Açılışta tablolar otomatik oluşturulur (`php artisan migrate --force`) ve
`ADMIN_EMAIL` / `ADMIN_PASSWORD` ile yönetici hesabı açılır.
Giriş adresi: `/giris`. (Demo şifreli test hesapları oluşturulmaz.)

## 5. Otomasyonun çalıştığını doğrulama

**Yönetim → Otomasyon** ekranında:

- *Zamanlayıcı: Çalışıyor* yazıyorsa `schedule:work` ayakta demektir.
- Her görevin son çalışma zamanı ve sonucu (başarılı / uyarı / hata) listelenir.
- "Şimdi XML çek" / "Şimdi Trendyol senkronu" düğmeleri ile zamanlayıcıyı
  beklemeden deneyebilirsiniz.

Kuyrukta bekleyen iş sayısı sürekli büyüyorsa `queue:work` süreci çalışmıyor
demektir.

## 6. Bayiye Trendyol'dan ürün gönderme

Yönetim → Bayiler → bayi seç → **Trendyol'a Ürün Gönder**:

1. Bayinin Trendyol satıcı numarası, API Key ve API Secret bilgilerini girin
   ("Bağlantıyı test et" ile doğrulayın).
2. Ürünleri, Trendyol kategorisi ve markasıyla seçip gönderin. Gönderim
   arka planda sürer; ilerleme aynı sayfadaki durum kutusunda görünür.
3. "Sonuç sorgula" ile Trendyol'un batch yanıtını okuyun; okunamayan batch'ler
   15 dakikada bir otomatik olarak yeniden kontrol edilir.

## 7. Güvenlik — ilk kurulumda yapılacaklar

Depoya geçmişte işlenmiş gerçek veriler (SQLite veritabanı) nedeniyle:

1. **Trendyol API anahtarını ve yönetici parolalarını yenileyin (rotate).**
2. Depo geçmişini temizleyin (BFG veya `git filter-repo` ile
   `database/database.sqlite` dosyasını geçmişten çıkarın) ve uzaktaki depoyu
   yeniden push edin.
3. `APP_KEY` değerini yeni bir değerle değiştirip anahtarları yeniden girin.
