<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Models\DealerTrendyolListing;
use App\Models\Product;
use App\Services\AutomationStatus;
use App\Services\DealerTrendyolService;
use App\Services\ProductReadiness;
use App\Services\QueueHealth;
use App\Services\TrendyolSendProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tek komutla tam teşhis: sunucu ortamı (kendi sunucuya taşındıktan sonra en
 * sık kırılan yerler), kuyruk/zamanlayıcı ve Trendyol gönderim hattı.
 *
 * Kullanım:
 *   php artisan bayiinet:teshis
 *   php artisan bayiinet:teshis --api --kuru --dealer=1
 */
class TrendyolDiagnose extends Command
{
    protected $signature = 'bayiinet:teshis
                            {--dealer= : Sadece bu bayi id için detay}
                            {--api : Trendyol API bağlantısını da test et (ağ isteği yapar)}
                            {--kuru : Gönderim öncesi kuru çalıştırma raporu (ilk N ürün)}
                            {--limit=20 : Kuru çalıştırmada incelenecek kalem sayısı}';

    protected $description = 'Sunucu, kuyruk, zamanlayıcı ve Trendyol entegrasyonu için tam teşhis raporu';

    public function handle(): int
    {
        $problems = 0;

        $this->newLine();
        $this->line('<options=bold>Bayiinet teşhis raporu</> · '.now()->format('d.m.Y H:i'));
        $this->newLine();

        $problems += $this->environment();
        $problems += $this->queue();
        $problems += $this->trendyolDealers();
        $problems += $this->failedJobs();

        $this->newLine();
        if ($problems === 0) {
            $this->info('Sonuç: belirgin bir sorun bulunamadı.');
        } else {
            $this->warn("Sonuç: {$problems} uyarı/sorun bulundu — yukarıdaki maddeleri sırayla giderin.");
        }

        return self::SUCCESS;
    }

    /** Ortam, dosya izinleri, veritabanı ve migration kontrolü. */
    private function environment(): int
    {
        $problems = 0;
        $this->line('<options=bold>1) Sunucu ve uygulama ortamı</>');

        $this->check(true, 'PHP sürümü', PHP_VERSION.' (en az 8.3 gerekir)');
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $problems++;
            $this->warn('   → PHP 8.3+ kurun: sudo apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath');
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            $problems++;
            $this->check(false, 'APP_KEY', 'tanımsız — php artisan key:generate çalıştırılmalı');
        } else {
            $this->check(true, 'APP_KEY', 'tanımlı ('.strlen($appKey).' karakter)');
        }

        $this->check(true, 'APP_ENV / APP_DEBUG', config('app.env').' / '.(config('app.debug') ? 'açık' : 'kapalı'));
        if (config('app.env') === 'production' && config('app.debug')) {
            $this->warn('   → Üretimde APP_DEBUG=true hata ayrıntılarını ziyaretçilere gösterir; kapatın.');
        }

        $this->check(true, 'APP_URL', (string) config('app.url'));

        // Yönlendirme/çerez uyumu: HTTPS yoksa güvenli çerez giriş yapılamaz hâle getirir.
        $urlScheme = str_starts_with((string) config('app.url'), 'https://') ? 'https' : 'http';
        $secureCookie = (bool) config('session.secure', false);
        if ($urlScheme === 'http' && $secureCookie) {
            $problems++;
            $this->check(false, 'Oturum çerezi', 'SESSION_SECURE_COOKIE=true ama APP_URL http — tarayıcı çerezi kaydetmez, giriş yapılamaz. '
                .'HTTPS kurun veya .env içinde SESSION_SECURE_COOKIE=false yapın.');
        } else {
            $this->check(true, 'Oturum çerezi', 'uyumlu ('.$urlScheme.', secure='.($secureCookie ? 'true' : 'false').')');
        }

        foreach (['storage' => storage_path(), 'önbellek' => base_path('bootstrap/cache')] as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $this->check($writable, $label.' yazılabilir', $path);
            if (! $writable) {
                $problems++;
                $this->warn('   → sudo chown -R www-data:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache');
            }
        }

        $link = public_path('storage');
        if (! file_exists($link)) {
            $problems++;
            $this->check(false, 'storage:link', 'public/storage sembolik bağlantısı yok — php artisan storage:link');
        } else {
            $this->check(true, 'storage:link', 'bağlantı var');
        }

        try {
            DB::connection()->getPdo();
            $this->check(true, 'Veritabanı', config('database.default').' · '.DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            $problems++;
            $this->check(false, 'Veritabanı', $e->getMessage());
            $this->warn('   → .env içindeki DB_* değerlerini kontrol edin; config önbelleğini temizleyin: php artisan config:clear');

            return $problems;
        }

        // Şema: kritik tablo/kolon kontrolü
        $expected = [
            'products' => ['sell_price', 'cost_price', 'xml_margin_percent', 'has_variants', 'images'],
            'product_variants' => ['variant_price', 'variant_stock', 'barcode'],
            'dealers' => ['trendyol_seller_id', 'trendyol_credentials'],
            'dealer_trendyol_listings' => ['barcode', 'status', 'batch_request_id'],
            'sources' => ['default_trendyol_brand_id', 'default_trendyol_category_id'],
        ];
        $missing = [];
        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table.' (tablo yok)';
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }
        if ($missing !== []) {
            $problems++;
            $this->check(false, 'Şema', 'eksik: '.implode(', ', $missing).' → php artisan migrate --force');
        } else {
            $this->check(true, 'Şema', 'kritik kolonlar tamam');
        }

        // Bekleyen migration var mı?
        try {
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles($migrator->paths()));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff($files, $ran));
            if ($pending !== []) {
                $problems++;
                $this->check(false, 'Migration', count($pending).' bekleyen dosya: '.implode(', ', array_slice($pending, 0, 5)).' → php artisan migrate --force');
            } else {
                $this->check(true, 'Migration', 'tümü çalışmış');
            }
        } catch (Throwable $e) {
            $this->check(false, 'Migration durumu', 'okunamadı: '.$e->getMessage());
        }

        // Sürücüler: yanlış sürücü seçimi sunucuda en sık görülen sessiz hatalardan.
        $queueDriver = (string) config('queue.default');
        if (in_array($queueDriver, ['sync', 'null'], true)) {
            $problems++;
            $this->check(false, 'Kuyruk sürücüsü', $queueDriver.' — işler isteğin içinde/atılarak çalışır; gönderim ve içe aktarma başarısız olur. .env: QUEUE_CONNECTION=database');
        } else {
            $this->check(true, 'Kuyruk sürücüsü', $queueDriver);
        }

        $cacheStore = (string) config('cache.default');
        if (in_array($cacheStore, ['array', 'null'], true)) {
            $problems++;
            $this->check(false, 'Önbellek sürücüsü', $cacheStore.' — işçi nabzı ve ilerleme sayaçları paylaşılamaz. .env: CACHE_STORE=database');
        } else {
            $this->check(true, 'Önbellek sürücüsü', $cacheStore);
        }

        $dbDriver = (string) config('database.default');
        if ($dbDriver === 'sqlite' && config('app.env') === 'production') {
            $problems++;
            $this->check(false, 'Veritabanı sürücüsü', 'sqlite — üretimde DB_CONNECTION=mysql olmalı; aksi hâlde veriler dosyaya yazılır ve kaybolur');
        }

        // PHP uzantıları: eksik uzantı = "could not find driver" / fatal error.
        // Zorunlu uzantılar (uygulama bunları doğrudan kullanıyor).
        // gd / bcmath / intl isteğe bağlıdır; eksikse uyarı verilmez.
        $extensions = [
            'pdo_mysql' => 'php8.3-mysql',
            'mbstring' => 'php8.3-mbstring',
            'dom' => 'php8.3-xml',
            'curl' => 'php8.3-curl',
            'zip' => 'php8.3-zip',
            'fileinfo' => 'php8.3-common',
        ];
        $missingExt = [];
        foreach ($extensions as $ext => $pkg) {
            if (! extension_loaded($ext)) {
                $missingExt[] = $ext.' ('.$pkg.')';
            }
        }
        if ($missingExt !== []) {
            $problems++;
            $this->check(false, 'PHP uzantıları', 'eksik: '.implode(', ', $missingExt).' → sudo apt install php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip && sudo systemctl restart php8.3-fpm');
        } else {
            $this->check(true, 'PHP uzantıları', 'gerekli uzantılar yüklü');
            foreach (['gd', 'bcmath', 'intl'] as $opt) {
                if (! extension_loaded($opt)) {
                    $this->line('   · isteğe bağlı uzantı yok: '.$opt.' (sorun değil)');
                }
            }
        }

        $free = @disk_free_space(storage_path());
        if (is_float($free) && $free < 512 * 1024 * 1024) {
            $problems++;
            $this->check(false, 'Disk alanı', 'storage altında yalnızca '.round($free / 1048576).' MB boş — log ve XML dosyaları için en az 512 MB önerilir');
        } elseif (is_float($free)) {
            $this->check(true, 'Disk alanı', round($free / 1073741824, 1).' GB boş');
        }

        $this->newLine();

        return $problems;
    }

    /** Kuyruk işçisi, zamanlayıcı ve bekleyen işler. */
    private function queue(): int
    {
        $problems = 0;
        $this->line('<options=bold>2) Kuyruk ve zamanlayıcı</>');

        $snapshot = QueueHealth::snapshot();

        $this->check($snapshot['worker_alive'], 'Kuyruk işçisi (queue:work)',
            $snapshot['worker_alive']
                ? 'çalışıyor · son nabız: '.($snapshot['worker_last_seen'] ?? '-')
                : 'SİNYAL YOK — Trendyol gönderimi ve XML içe aktarma ilerlemez');
        if (! $snapshot['worker_alive']) {
            $problems++;
            $this->warn('   → sudo systemctl start bayiinet-queue  (kurulum: deploy/oracle/install.sh)');
        }

        $alive = AutomationStatus::schedulerAlive();
        $scheduler = AutomationStatus::get('scheduler');
        $this->check($alive, 'Zamanlayıcı (schedule:work)', $alive ? 'çalışıyor · son nabız: '.($scheduler['ran_at'] ?? '-') : 'SİNYAL YOK — saatlik XML/Trendyol görevleri çalışmaz');
        if (! $alive) {
            $problems++;
            $this->warn('   → sudo systemctl start bayiinet-schedule  ya da cron: * * * * * cd /var/www/bayiinet && php artisan schedule:run >> /dev/null 2>&1');
        }

        $this->line(sprintf(
            '   Bekleyen iş: %d (varsayılan %d · marketplace %d) · başarısız: %d · en eski bekleyen: %s',
            $snapshot['pending'],
            $snapshot['pending_default'],
            $snapshot['pending_marketplace'],
            $snapshot['failed'],
            QueueHealth::humanSeconds($snapshot['oldest_seconds']),
        ));
        if ($snapshot['stuck']) {
            $problems++;
            $this->warn('   → '.$snapshot['advice']);
        }

        if ($snapshot['pending'] > 0 && ! $snapshot['worker_alive']) {
            $this->warn('   → İşler birikmiş: işçi başladığında sırayla işlenecek. Kuyruğu boşaltmak için: php artisan queue:work --queue=default,marketplace --stop-when-empty');
        }

        $this->line('   CACHE_STORE: '.config('cache.default').' · QUEUE_CONNECTION: '.config('queue.default').' (database beklenir)');
        if (config('queue.default') !== 'database') {
            $this->warn('   → Kuyruk bağlantısı "database" olmalı; sync kullanılırsa ağır işler istek içinde çalışır ve zaman aşımına düşer.');
        }

        $this->newLine();

        return $problems;
    }

    /** Bayi bazlı Trendyol durumu. */
    private function trendyolDealers(): int
    {
        $problems = 0;
        $this->line('<options=bold>3) Trendyol entegrasyonu</>');

        $query = Dealer::query()->whereNotNull('trendyol_seller_id');
        if ($id = $this->option('dealer')) {
            $query->where('id', (int) $id);
        }
        $dealers = $query->get();

        if ($dealers->isEmpty()) {
            $this->warn('   Trendyol mağaza numarası tanımlı bayi yok (Bayiler → bayi → Trendyol ekranı).');
            $problems++;
            $this->newLine();

            return $problems;
        }

        foreach ($dealers as $dealer) {
            $this->newLine();
            $this->line('  <options=bold>'.$dealer->company_name.' (#'.$dealer->id.')</> · mağaza: '.$dealer->trendyol_seller_id.' · durum: '.$dealer->status);

            $credentials = $dealer->trendyol_credentials;
            $credOk = is_array($credentials) && ! empty($credentials['api_key']) && ! empty($credentials['api_secret']);
            $this->check($credOk, 'API bilgileri', $credOk
                ? 'çözümlendi (kayıtlı anahtar okunabiliyor)'
                : 'API Key/Secret okunamadı — APP_KEY değişmiş olabilir; bilgileri yeniden girin');
            if (! $credOk) {
                $problems++;
            }

            if ($dealer->trendyol_last_error) {
                $this->warn('   Son hata: '.$dealer->trendyol_last_error);
            }

            if ($this->option('api') && $credOk) {
                try {
                    app(DealerTrendyolService::class)->testConnection($dealer);
                    $this->check(true, 'API bağlantı testi', 'başarılı');
                } catch (Throwable $e) {
                    $problems++;
                    $this->check(false, 'API bağlantı testi', $e->getMessage());
                }
            } else {
                $this->line('   API bağlantı testi atlandı (--api ile çalıştırın).');
            }

            $statusCounts = DealerTrendyolListing::query()
                ->where('dealer_id', $dealer->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
            $this->line('   İlan durumları: '.(count($statusCounts) > 0
                ? collect($statusCounts)->map(fn ($v, $k) => $k.': '.$v)->implode(' · ')
                : 'henüz gönderim yok'));

            $stale = DealerTrendyolListing::query()
                ->where('dealer_id', $dealer->id)
                ->where('status', 'sent')
                ->where('sent_at', '<', now()->subMinutes(30))
                ->count();
            if ($stale > 0) {
                $this->warn('   '.$stale.' kalem 30 dakikadan uzun süredir doğrulama bekliyor → panelde «Gönderildi olanları kontrol et» butonuna basın.');
            }

            $progress = TrendyolSendProgress::get($dealer->id);
            if (! empty($progress)) {
                $this->line('   Gönderim durumu: '.($progress['status'] ?? '-').' · '.($progress['message'] ?? ''));
            }

            // Gönderime hazırlık: eksik verili ürünler
            $readinessResult = ProductReadiness::countsWithError();
            $readiness = $readinessResult['counts'];
            if ($readinessResult['error'] !== null) {
                $problems++;
                $this->check(false, 'Hazırlık sayımı', 'SQL hatası: '.$readinessResult['error']);
            }
            $this->line(sprintf(
                '   Gönderilebilir ürün: %d · görselsiz: %d · barkodsuz: %d · fiyatsız: %d',
                $readiness['total'],
                $readiness['missing_image'],
                $readiness['missing_barcode'],
                $readiness['missing_price'],
            ));
            if ($readiness['missing_image'] > 0) {
                $this->warn('   → Görselsiz ürünler Trendyol tarafından reddedilir; XML kaynağındaki Image alanını kontrol edin.');
            }

            $fallbackCategory = app(\App\Services\TrendyolCategoryMatcher::class)->fallbackCategoryId();
            $sourceDefaults = \App\Models\Source::query()
                ->whereNotNull('default_trendyol_category_id')
                ->where('default_trendyol_category_id', '>', 0)
                ->count();
            if ($fallbackCategory <= 0 && $sourceDefaults === 0) {
                $problems++;
                $this->check(false, 'Kategori kaynağı', 'Ne form varsayılanı ne XML kaynağı kategori no tanımlı — otomatik eşleme tutmazsa ürünler reddedilir');
            } else {
                $this->check(true, 'Kategori kaynağı', 'varsayılan no: '.($fallbackCategory > 0 ? $fallbackCategory : '-').' · kategori tanımlı kaynak: '.$sourceDefaults);
            }

            if ($this->option('kuru')) {
                $this->dryRunFor($dealer, max(1, (int) $this->option('limit')));
            }
        }

        $this->newLine();

        return $problems;
    }

    private function dryRunFor(Dealer $dealer, int $limit): void
    {
        $ids = Product::query()->sendable()->orderBy('id')->limit(500)->pluck('id')->all();
        if ($ids === []) {
            $this->warn('   Kuru çalıştırma: gönderilebilir ürün yok.');

            return;
        }

        $result = app(DealerTrendyolService::class)->preview($dealer, $ids, null, null, $limit);

        $this->line('   Kuru çalıştırma (Trendyol\'a istek gitmedi): '
            .$result['ready'].' hazır / '.$result['blocked'].' engelli / '.$result['total'].' kalem');

        foreach (array_slice($result['reason_counts'], 0, 5, true) as $reason => $count) {
            $this->line('     · '.$count.'× '.$reason);
        }

        foreach ($result['items'] as $item) {
            if ($item['ok']) {
                continue;
            }
            $this->warn('     ✗ '.$item['label'].' → '.implode(' | ', $item['reasons']));
        }
    }

    private function failedJobs(): int
    {
        $this->line('<options=bold>4) Başarısız işler</>');

        try {
            if (! Schema::hasTable('failed_jobs')) {
                $this->line('   failed_jobs tablosu yok.');

                return 0;
            }

            $count = (int) DB::table('failed_jobs')->count();
            $this->line('   Toplam başarısız iş: '.$count);

            if ($count === 0) {
                return 0;
            }

            $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get();
            foreach ($rows as $row) {
                $exception = (string) ($row->exception ?? '');
                $firstLine = trim(strtok($exception, "\n") ?: '');
                $this->warn('   #'.$row->id.' ['.$row->queue.'] '.mb_substr($firstLine, 0, 160));
            }
            $this->line('   Yeniden denemek için: php artisan queue:retry all · Temizlemek için: php artisan queue:flush');

            return 1;
        } catch (Throwable $e) {
            $this->check(false, 'Başarısız iş kayıtları', $e->getMessage());

            return 1;
        }
    }

    private function check(bool $ok, string $label, string $detail): void
    {
        $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line(sprintf('  %s %s: %s', $icon, $label, $detail));
    }
}
