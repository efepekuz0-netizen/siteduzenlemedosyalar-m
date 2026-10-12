# Bayiinet — geliştirici notları

Bu depo bir **Laravel 12 / PHP 8.3** uygulamasıdır: tedarikçi XML'lerini içe
aktarır, bayilere özel XML feed üretir, bayilerin Trendyol mağazalarına ürün
gönderir.

## Komutlar

```bash
php artisan serve                                   # geliştirme sunucusu
php artisan queue:work --queue=marketplace,default,maintenance   # işçi 1 (Trendyol/panel)
php artisan queue:work --queue=maintenance                       # işçi 2 (XML + toplu fiyat)
php artisan schedule:work                           # zamanlanmış görevler
php artisan migrate --seed                          # kurulum
php artisan bayiinet:ensure-admin                   # yönetici hesabı
php artisan bayiinet:teshis [--api] [--kuru]        # tam teşhis (ortam+kuyruk+Trendyol)
php artisan test                                    # testler (yoksa eklenmeli)
```

Üretim (Oracle Cloud / Ubuntu): `deploy/oracle/install.sh` kurar, güncelleme
için `deploy/oracle/deploy.sh`. Süreçler systemd ile yönetilir:

```bash
sudo systemctl status|restart bayiinet-queue bayiinet-maintenance bayiinet-schedule
```

Kuyruk ve zamanlayıcı **ayrı süreçlerdir**; çalışmıyorsa XML içe aktarma ve
Trendyol gönderimi sessizce bekler. Durum `Yönetim → Otomasyon` ve
`Yönetim → Sistem Sağlığı` ekranlarından görülür (`QueueHealth` nabzı).

### Kuyruk sözleşmesi (bozulmamalı)

| Kuyruk | Ne çalışır | Kim tüketir |
|---|---|---|
| `marketplace` | Trendyol gönderimi, batch doğrulama, ilan silme | `bayiinet-queue` (ilk sırada) |
| `default` | Panelden tetiklenen kısa işler | `bayiinet-queue` |
| `maintenance` | XML içe aktarma, toplu fiyat, katalog/stok senkronu (840 sn'ye kadar) | `bayiinet-maintenance` (+ `bayiinet-queue` en son sırada) |

- **Yeni bir ağır iş eklerken `maintenance` kuyruğunu kullanın.** Aksi hâlde
  tek işçi meşgul olur ve Trendyol gönderimi dakikalarca bekler.
- Kuyrukları HTTP isteği içinde tüketmeyin (`QUEUE_INLINE_DRAIN` yalnızca
  işçi süreci olmayan konteyner ortamları içindir; kendi sunucuda `false`).
- İş `$timeout` ≤ 840 sn ve `<` `DB_QUEUE_RETRY_AFTER` (960) olmalıdır;
  işçi `--timeout=900` bu aralığın ortasıdır.

## Yapı

- `app/Http/Controllers/Admin` — yönetim paneli (Türkçe rota adları: `/admin/kaynaklar`, `/admin/urunler`…)
- `app/Http/Controllers/Dealer` — bayi paneli (kök yollar: `/panel`, `/katalog`)
- `app/Jobs` — ağır işler: `ImportSourceJob`, `SendDealerTrendyolCatalog`,
  `SendDealerTrendyolBatch`, `VerifyTrendyolBatch`, `SyncDealerTrendyolInventory`
- `app/Services`
  - `XmlImportService` — XML ayrıştırma, varyant eşitleme, toplu fiyatlama
  - `PricingService` — kâr oranı / satış fiyatı hesapları (toplu güncelleme)
  - `DealerTrendyolService` — Trendyol ürün gönderimi, fiyat/stok, batch sonucu
  - `TrendyolSendProgress` — gönderim durumunun tek kaynağı (cache tabanlı sayaçlar)
  - `TrendyolCategoryMatcher` — XML kategorisi → Trendyol kategorisi eşlemesi
  - `QueueHealth` — kuyruk işçisi/zamanlayıcı nabzı (panelde "işçi çalışmıyor" uyarısı)
  - `ProductReadiness` — gönderime hazır ürün sayımı (JSON kolonu güvenli tek SQL)
  - `AutomationStatus` — zamanlanmış görevlerin son çalışma/durum kaydı
  - `AdminAudit` — kritik yönetici eylemlerinin denetim izi
- `routes/console.php` — zamanlayıcı tanımları (saatlik XML/Trendyol, 15 dk batch)
- `config/bayiinet.php` — uygulamaya özel ayarlar (admin, trendyol, automation)

## Sözleşmeler / dikkat edilecekler

- **Arayüz ve mesajlar Türkçe.** Yeni metinleri Türkçe yazın.
- **Kuyruk işçisi birinci sınıf bağımlılıktır.** XML içe aktarma ve Trendyol
  gönderimi `queue:work` olmadan hiç ilerlemez. İşçi nabzı `App\Services\QueueHealth`
  ile tutulur (AppServiceProvider → `JobProcessing`/`WorkerIdle` olayları);
  panelde *Sistem Sağlığı* ve *Otomasyon* ekranları bunu gösterir. Yeni uzun
  işlerde işçiyi bloke eden senkron bekleme eklemeyin.
- **Uzun işleri HTTP isteği içinde yapmayın** — kuyruğa atın
  (`ImportSourceJob` vb.). 15.000 ürünlük katalogda istek zaman aşımına düşer.
- **N+1'den kaçının**: listelerde `with()` kullanın, `Product::effective_stock`
  yüklü ilişkiyi kullanır.
- **Fiyatlar tek yerden hesaplanır**: `PricingService`. Ürün başına `save()`
  yerine toplu güncelleme (`recalculateImported`) tercih edilir.
- **Kuyruk ayrımı:** etkileşimli işler `marketplace` (Trendyol gönderim/doğrulama,
  ilan silme), panel işleri `default`, ağır işler (`ImportSourceJob`,
  `ApplyBulkXmlMargin`, `SyncTrendyolCatalog`, `SyncDealerTrendyolInventory`)
  `maintenance`. Ağır işleri `default` kuyruğuna almak Trendyol gönderimini
  dakikalarca bloke eder (eski hata).
- **Job zaman aşımı < kuyruk `retry_after` (960 sn)** olmalı; aksi hâlde iş
  ikinci bir işçiye düşer. Yeni işlerde `$timeout` en fazla 840 olmalı.
- **Yeni migration'lar idempotent olsun** (`Schema::hasColumn` kontrollü),
  çünkü üretimde eksik çalışmış migration'lar var.
- Modelden ölü kolonları temiz tutun: `$fillable` içindeki her alan migration'da
  karşılığı olmalı.
- Git'e asla `vendor/`, `.env`, `database/*.sqlite`, derlenmiş varlık koymayın.
