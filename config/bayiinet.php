<?php

/*
 * Bayiinet'e özgü yapılandırma.
 * Tüm ortam değişkenleri yalnızca buradan okunur; uygulama kodunda env() kullanılmaz
 * (config:cache ile çalışırken env() null döner).
 */

return [

    /*
     * İlk kurulumda oluşturulacak yönetici hesabı.
     * docker/start.sh -> php artisan bayiinet:ensure-admin
     * (Oracle Cloud kurulumunda deploy/oracle/install.sh aynı komutu çağırır.)
     */
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'min_password_length' => 8,
    ],

    /*
     * Trendyol entegrasyonu varsayılanları.
     * Kâr/komisyon ayarları veritabanındaki pazaryeri bağlantısından gelir;
     * buradaki değerler yalnızca bağlantı tanımlı değilse kullanılır.
     */
        // /health-detail teşhis uç noktası anahtarı. Boş bırakılırsa uç nokta
    // yalnızca sunucunun kendisinden erişilebilir olur (önerilen).
    'health_token' => env('HEALTH_DETAIL_TOKEN', ''),

    'trendyol' => [
        // Tek job'un Trendyol'a gönderdiği ürün sayısı
        'batch_size' => max(1, (int) env('TRENDYOL_BATCH_SIZE', 40)),
        // Trendyol'da markası bulunamayan ürünler için son çare marka numarası
        'fallback_brand_id' => (int) env('TRENDYOL_FALLBACK_BRAND_ID', 2613880),
        // Batch sonucunun senkron bekleneceği en fazla süre (saniye)
        'batch_wait_seconds' => (int) env('TRENDYOL_BATCH_WAIT', 9),
        // Onaylı ilan listesi taranırken izin verilen en fazla sayfa
        'max_inventory_pages' => (int) env('TRENDYOL_MAX_PAGES', 100),
        // API hatalarında yeniden deneme
        'retry_times' => (int) env('TRENDYOL_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('TRENDYOL_RETRY_SLEEP', 1500),
    ],

    /*
     * Trendyol fiyat motoru (masaüstü pricing_engine ile aynı formül):
     *   S = (maliyet + kargo + kâr) / (1 - komisyon), xx.99 yuvarlama
     * Komisyon ve kâr artık ortam değişkeninden ayarlanabilir; eskiden kod
     * içinde sabitti (%15 komisyon, 10 TL min. kâr).
     */
    'pricing' => [
        // Trendyol komisyonu (%) — kategoriye göre değişir, ortalama varsayılan
        'commission_rate' => (float) env('TRENDYOL_COMMISSION_RATE', 15),
        // Satış başına en az kâr (TL)
        'min_profit' => (float) env('TRENDYOL_MIN_PROFIT', 10),
        // Yuvarlama son hanesi (0.99 → 129.99)
        'round_to' => (float) env('TRENDYOL_ROUND_TO', 0.99),
        // Kargo tarifesi tipi: standart | hizli
        'delivery_type' => (string) env('TRENDYOL_DELIVERY_TYPE', 'standart'),
        // XML'de desi yoksa kullanılacak varsayılan desi
        'default_desi' => (float) env('TRENDYOL_DEFAULT_DESI', 5),
    ],

    /*
     * Kuyruk davranışı.
     *
     * inline_drain: Render'daki "worker yoksa istek sonunda kuyruğu tüket"
     * hilesi. Kendi sunucuda gerçek bir sistem işçisi (systemd/supervisor)
     * çalıştığı için varsayılan KAPALI. Kapalıyken web isteği içinde
     * `queue:work` çalıştırılmaz (istek zaman aşımını ve kilit çakışmasını
     * önler). Yalnızca ayrı worker servisi olmayan ortamlarda açın.
     */
    'queue' => [
        'inline_drain' => filter_var(env('QUEUE_INLINE_DRAIN', false), FILTER_VALIDATE_BOOLEAN),
        'inline_drain_loops' => max(1, (int) env('QUEUE_INLINE_DRAIN_LOOPS', 3)),
    ],

    /*
     * Zamanlanmış görevler (saatlik XML yenileme / Trendyol senkronu).
     */
    'automation' => [
        'xml_sync' => (bool) env('AUTO_XML_SYNC', true),
        'trendyol_sync' => (bool) env('AUTO_TRENDYOL_SYNC', true),
        // Zamanlayıcının ne kadar süre içinde çalışması gerektiği (uyarı eşiği, dakika)
        'heartbeat_max_delay' => (int) env('AUTOMATION_HEARTBEAT_DELAY', 75),
    ],

    'hepsiburada' => [
        'base_url' => env('HEPSIBURADA_BASE_URL', 'https://mpop.hepsiburada.com'),
    ],
];
