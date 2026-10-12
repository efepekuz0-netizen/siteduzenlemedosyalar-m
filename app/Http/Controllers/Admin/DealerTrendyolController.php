<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteDealerTrendyolProducts;
use App\Jobs\SendDealerTrendyolCatalog;
use App\Jobs\SendDealerTrendyolRun;
use App\Models\Dealer;
use App\Models\Product;
use App\Models\Source;
use App\Services\AdminAudit;
use App\Services\DealerTrendyolService;
use App\Services\ProductReadiness;
use App\Services\QueueHealth;
use App\Services\TrendyolCategoryMatcher;
use App\Services\TrendyolSendProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class DealerTrendyolController extends Controller
{
    public function __construct(private readonly DealerTrendyolService $trendyol) {}

    public function index(Request $request, Dealer $dealer)
    {
        $search = trim((string) $request->get('q', ''));

        $products = Product::query()
            ->where('is_active', true)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('stock_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->orderBy('title')
            ->paginate(30)
            ->withQueryString();

        $margin = $this->trendyol->dealerMargin($dealer);
        $prices = [];
        foreach ($products as $product) {
            $prices[$product->id] = $this->trendyol->salePrice($product, null, $margin);
        }

        $listed = $dealer->trendyolListings()->pluck('status', 'product_id');
        $counts = $dealer->trendyolListings()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $recent = $dealer->trendyolListings()
            ->with('product:id,title,stock_code')
            ->latest('updated_at')
            ->take(50)
            ->get();
        $problematic = $dealer->trendyolListings()
            ->with('product:id,title,stock_code')
            ->where('status', 'failed')
            ->latest('updated_at')
            ->take(30)
            ->get();
        $batches = $dealer->trendyolListings()
            ->whereNotNull('batch_request_id')
            ->latest('sent_at')
            ->pluck('batch_request_id')
            ->unique()
            ->take(10);

        $catMap = app(TrendyolCategoryMatcher::class)->map();
        $defaultCategoryId = (int) ($catMap['fallback_id'] ?? 0) ?: old('category_id');
        $defaultBrandId = (int) ($catMap['fallback_brand_id'] ?? 0) ?: old('brand_id');

        $sendStatus = TrendyolSendProgress::get($dealer->id);
        $deleteStatus = Cache::get('trendyol_delete_status_'.$dealer->id);
        $sources = Source::query()->orderBy('name')->get(['id', 'name']);

        // Panel kontrolleri: kuyruk işçisi yaşıyor mu, hangi batch'ler
        // sonuç bekliyor, ürünler gönderime hazır mı?
        $queue = QueueHealth::snapshot();
        $readiness = ProductReadiness::counts();
        $readiness['already_created'] = (int) ($counts['created'] ?? 0);
        $readiness['ready_total'] = max(0, $readiness['total'] - $readiness['missing_image'] - $readiness['missing_barcode'] - $readiness['missing_price']);
        $staleVerification = $dealer->trendyolListings()
            ->where('status', 'sent')
            ->where('sent_at', '<', now()->subMinutes(30))
            ->count();

        return view('admin.dealers.trendyol', compact(
            'dealer', 'products', 'prices', 'margin', 'listed', 'counts', 'recent', 'problematic', 'batches', 'search',
            'defaultCategoryId', 'defaultBrandId', 'sendStatus', 'deleteStatus', 'sources',
            'queue', 'readiness', 'staleVerification',
        ));
    }

    public function saveConnection(Request $request, Dealer $dealer): RedirectResponse
    {
        $data = $request->validate([
            'trendyol_seller_id' => 'required|string|max:30',
            'api_key' => 'nullable|string|max:255',
            'api_secret' => 'nullable|string|max:255',
            'default_marketplace_margin' => 'required|numeric|min:0|max:500',
        ]);

        $existing = $dealer->trendyol_credentials ?? [];
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: ($existing['api_key'] ?? null);
        $apiSecret = trim((string) ($data['api_secret'] ?? '')) ?: ($existing['api_secret'] ?? null);

        if (! $apiKey || ! $apiSecret) {
            return $this->back($dealer)->with('error', 'API Key ve API Secret girilmeli.');
        }

        $dealer->update([
            'trendyol_seller_id' => trim($data['trendyol_seller_id']),
            'trendyol_credentials' => ['api_key' => $apiKey, 'api_secret' => $apiSecret],
            'default_marketplace_margin' => $data['default_marketplace_margin'],
            'trendyol_last_error' => null,
        ]);

        return $this->runTest($dealer->fresh(), 'Bilgiler kaydedildi ve Trendyol bağlantısı başarılı.');
    }

    public function test(Dealer $dealer): RedirectResponse
    {
        return $this->runTest($dealer, 'Trendyol bağlantısı başarılı.');
    }

    /**
     * Kuru çalıştırma: seçilen ürünler için Trendyol'a HİÇBİR istek gitmeden
     * barkod/fiyat/görsel/kategori/marka doğrulaması yapılır ve eksikler
     * gerekçeleriyle listelenir.
     */
    public function dryRun(Request $request, Dealer $dealer): RedirectResponse
    {
        $data = $request->validate([
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'send_all' => 'nullable|boolean',
            'category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
        ]);

        if ($request->boolean('send_all')) {
            $ids = Product::query()->sendable()->orderBy('id')->limit(500)->pluck('id')->all();
        } else {
            $ids = array_map('intval', $data['product_ids'] ?? []);
        }

        if ($ids === []) {
            return $this->back($dealer)->with('error', 'Kontrol için ürün seçin veya «Tüm ürünleri gönder» seçeneğini kullanın.');
        }

        $result = $this->trendyol->preview(
            $dealer,
            $ids,
            (int) ($data['category_id'] ?? 0) ?: null,
            (int) ($data['brand_id'] ?? 0) ?: null,
            100,
        );

        session()->flash('dryRun', $result);

        $message = sprintf(
            'Kuru çalıştırma: %d kalem incelendi · %d gönderime hazır · %d engelli.',
            $result['total'],
            $result['ready'],
            $result['blocked'],
        );

        return $this->back($dealer)->with($result['blocked'] > 0 ? 'error' : 'success', $message);
    }

    public function send(Request $request, Dealer $dealer, TrendyolCategoryMatcher $matcher): RedirectResponse
    {
        $data = $request->validate([
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'send_all' => 'nullable|boolean',
            'category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'attributes_json' => 'nullable|string|max:20000',
            'remember_category' => 'nullable|boolean',
        ]);

        if (! $dealer->isActive()) {
            return $this->back($dealer)->with('error', 'Bayi onaylı (aktif) değil.');
        }

        $sendAll = $request->boolean('send_all');
        if ($sendAll) {
            // Limitsiz — job tüm stoklu aktif ürünleri alır
            $productIds = null;
            $countHint = Product::query()->sendable()->count();
            if ($countHint === 0) {
                return $this->back($dealer)->with('error', 'Gönderilecek stoklu ürün yok.');
            }
        } else {
            $productIds = array_map('intval', $data['product_ids'] ?? []);
            if ($productIds === []) {
                return $this->back($dealer)->with('error', 'Gönderilecek ürün seçilmedi. Tümünü gönder veya listeden seçin.');
            }
            $countHint = count($productIds);
        }

        $attributes = [];
        $json = trim((string) ($data['attributes_json'] ?? ''));
        if ($json !== '') {
            $attributes = json_decode($json, true);
            $valid = is_array($attributes) && array_is_list($attributes)
                && collect($attributes)->every(fn ($row) => is_array($row));
            if (! $valid) {
                return $this->back($dealer)->withInput()->with('error', 'Özellikler alanı geçerli bir JSON listesi olmalı. Örnek: [{"attributeId":338,"attributeValueId":6980}]');
            }
        }

        $categoryId = (int) $data['category_id'];
        $brandId = (int) $data['brand_id'];

        // Verilen kategori/markayı varsayılan olarak hatırla (otomatik eşleme yedegi)
        if ($categoryId > 0 || $brandId > 0) {
            $matcher->setFallback($categoryId, $brandId);
        }

        if (! $dealer->hasTrendyolCredentials()) {
            return $this->back($dealer)->with('error', 'Önce Trendyol API bilgilerini kaydedin.');
        }

        // Kuyruk işçisi çalışmıyorsa gönderim başlatmak kullanıcıyı yanıltır:
        // işler jobs tablosunda bekler ve hiçbir şey olmaz.
        $queue = QueueHealth::snapshot();
        if (! $queue['worker_alive'] && ! config('bayiinet.queue.inline_drain')) {
            return $this->back($dealer)->with(
                'error',
                'Kuyruk işçisi (queue:work) çalışmıyor — gönderim başlatılsa da işlenmez. '
                .'Sunucuda `sudo systemctl start bayiinet-queue` komutunu çalıştırıp tekrar deneyin. '
                .'Durumu «Sistem Sağlığı» ekranından izleyebilirsiniz.'
            );
        }

        if (TrendyolSendProgress::isActive($dealer->id)) {
            return $this->back($dealer)->with(
                'error',
                'Bu bayi için gönderim zaten devam ediyor: '.(TrendyolSendProgress::get($dealer->id)['message'] ?? 'çalışıyor').' Önce «Gönderimi durdur» butonuna basın.'
            );
        }

        // Önceki gönderimden kalan benzersiz iş kilidini serbest bırak (en iyi çaba)
        try {
            Cache::lock('laravel_unique_job:trendyol-send-'.$dealer->id)->forceRelease();
            Cache::lock('laravel_unique_job:trendyol-run-'.$dealer->id)->forceRelease();
        } catch (Throwable) {
        }

        // Sayfa anında dönsün — gönderim kuyrukta
        TrendyolSendProgress::queued($dealer->id, $countHint.' ürün kuyruğa alındı…');

        // Marka formda veya herhangi bir XML kaynağında tanımlıysa masaüstü hızı (100'lük paket, tek iş)
        $hasBrand = $brandId > 0 || Source::query()
            ->whereNotNull('default_trendyol_brand_id')
            ->where('default_trendyol_brand_id', '>', 0)
            ->exists();

        if ($hasBrand) {
            SendDealerTrendyolRun::dispatch(
                $dealer->id,
                $sendAll ? null : $productIds,
                $categoryId > 0 ? $categoryId : null,
                $brandId > 0 ? $brandId : null,
                $attributes,
            );
            $mode = 'masaustu hiz (100 urun/paket)';
        } else {
            SendDealerTrendyolCatalog::dispatch(
                $dealer->id,
                $sendAll ? null : $productIds,
                $categoryId > 0 ? $categoryId : null,
                $brandId > 0 ? $brandId : null,
                $attributes,
                false,
            );
            $mode = 'guvenli mod (5 urun/paket) — XML kaynagina Marka No ekleyerek hizlanir';
        }

        // Render'daki "işçi yok" hilesi: yalnızca QUEUE_INLINE_DRAIN=true ise
        // (ayrı worker servisi olmayan ortamlar) web isteği içinde kuyruk tüketilir.
        // Kendi sunucuda gerçek systemd işçisi çalıştığı için varsayılan kapalı.
        if (config('bayiinet.queue.inline_drain')) {
            dispatch(function () {
                try {
                    $loops = max(1, (int) config('bayiinet.queue.inline_drain_loops', 3));
                    for ($i = 0; $i < $loops; $i++) {
                        \Illuminate\Support\Facades\Artisan::call('queue:work', [
                            'connection' => 'database',
                            '--queue' => 'marketplace',
                            '--once' => true,
                            '--tries' => 1,
                            '--timeout' => 120,
                            '--memory' => 384,
                        ]);
                    }
                } catch (Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('trendyol afterResponse drain', ['e' => $e->getMessage()]);
                }
            })->afterResponse();
        }

        $n = $sendAll ? 'tum stoklu urunler' : (count($productIds).' urun');

        AdminAudit::log('trendyol.send', $dealer->company_name.' icin '.$n.' Trendyol kuyruga alindi.', [
            'dealer_id' => $dealer->id,
            'send_all' => $sendAll,
            'product_ids' => $sendAll ? null : $productIds,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'mode' => $mode,
        ]);

        return $this->back($dealer)->with(
            'success',
            $n.' kuyruga alindi · '.$mode.'. Sayfa yenilenince ilerlemeyi gorursunuz.'
        );
    }

    public function cancelSend(Dealer $dealer): RedirectResponse
    {
        // Kuyruktaki batch işleri durumu her adımda kontrol eder; iptal bayrağını
        // gördüklerinde Trendyol'a hiç dokunmadan kendilerini atlarlar.
        TrendyolSendProgress::cancel(
            $dealer->id,
            'Gönderim durduruldu. Kuyruktaki işler atlanıyor; yeni gönderim başlatabilirsiniz.'
        );

        // Benzersiz iş kilidi serbest (en iyi çaba)
        try {
            Cache::lock('laravel_unique_job:trendyol-send-'.$dealer->id)->forceRelease();
            Cache::lock('laravel_unique_job:trendyol-run-'.$dealer->id)->forceRelease();
        } catch (Throwable) {
        }

        return $this->back($dealer)->with('success', 'Trendyol gönderimi durduruldu. Kuyrukta bekleyen işler atlanacak.');
    }

    public function deleteProducts(Request $request, Dealer $dealer): RedirectResponse
    {
        if (! $dealer->hasTrendyolCredentials()) {
            return $this->back($dealer)->with('error', 'Trendyol API bilgileri eksik.');
        }

        $data = $request->validate([
            'scope' => 'required|string',
            'confirm' => 'required|accepted',
        ]);

        $scope = $data['scope'];
        if ($scope === 'all') {
            $jobScope = 'all';
            $label = 'tüm Trendyol ürünleri';
        } elseif (str_starts_with($scope, 'source:')) {
            $sourceId = (int) substr($scope, 7);
            if (! Source::query()->whereKey($sourceId)->exists()) {
                return $this->back($dealer)->with('error', 'XML kaynağı bulunamadı.');
            }
            $jobScope = $sourceId;
            $src = Source::query()->find($sourceId);
            $label = '«'.($src->name ?? $sourceId).'» kaynağı ürünleri';
        } else {
            return $this->back($dealer)->with('error', 'Geçersiz silme kapsamı.');
        }

        Cache::put('trendyol_delete_status_'.$dealer->id, [
            'status' => 'queued',
            'message' => $label.' silme kuyruğunda…',
        ], now()->addHours(2));

        DeleteDealerTrendyolProducts::dispatch($dealer->id, $jobScope);

        // Toplu silme geri alınamaz: denetim izine yaz
        AdminAudit::log('trendyol.delete', $dealer->company_name.' için '.$label." Trendyol'dan silinmek üzere kuyruğa alındı.", [
            'dealer_id' => $dealer->id,
            'scope' => $jobScope,
        ]);

        return $this->back($dealer)->with('success', $label.' Trendyol silme kuyruğuna alındı.');
    }

    public function recheckBatches(Dealer $dealer): RedirectResponse
    {
        try {
            $result = $this->trendyol->recheckSentBatches($dealer);
        } catch (LogicException $e) {
            return $this->back($dealer)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->back($dealer)->with('error', 'Kontrol başarısız: '.$e->getMessage());
        }

        $msg = "{$result['batches']} batch kontrol: {$result['created']} oluştu, {$result['failed']} hatalı, {$result['pending']} bekliyor.";
        if (! empty($result['errors'])) {
            $msg .= ' Örnek: '.implode(' | ', array_slice($result['errors'], 0, 3));
        }

        return $this->back($dealer)->with(
            ($result['created'] > 0 || $result['failed'] > 0) ? 'success' : 'error',
            $msg
        );
    }

    public function checkBatch(Request $request, Dealer $dealer): RedirectResponse
    {
        $data = $request->validate(['batch_request_id' => 'required|string|max:100']);

        try {
            $result = $this->trendyol->checkBatch($dealer, $data['batch_request_id']);
        } catch (LogicException $e) {
            return $this->back($dealer)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->back($dealer)->with('error', 'Sonuç sorgulanamadı: '.$e->getMessage());
        }

        return $this->back($dealer)->with(
            'success',
            "Trendyol işlem durumu: {$result['status']}. Oluşan: {$result['created']}, hatalı: {$result['failed']}."
        );
    }

    public function syncInventory(Dealer $dealer): RedirectResponse
    {
        try {
            $result = $this->trendyol->syncInventory($dealer);
        } catch (LogicException $e) {
            return $this->back($dealer)->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->back($dealer)->with('error', 'Fiyat/stok güncellenemedi: '.$e->getMessage());
        }

        return $this->back($dealer)->with('success', "{$result['updated']} ürünün fiyat ve stoğu Trendyol'a gönderildi.");
    }

    private function runTest(Dealer $dealer, string $successMessage): RedirectResponse
    {
        try {
            $this->trendyol->testConnection($dealer);
            $dealer->update(['trendyol_last_error' => null]);

            return $this->back($dealer)->with('success', $successMessage);
        } catch (Throwable $e) {
            $dealer->update(['trendyol_last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            return $this->back($dealer)->with('error', $e->getMessage());
        }
    }

    private function back(Dealer $dealer): RedirectResponse
    {
        return redirect()->route('admin.dealers.trendyol', $dealer);
    }

    public function hepsiburada(Dealer $dealer)
    {
        return view('admin.dealers.hepsiburada', compact('dealer'));
    }

    public function saveHepsiburada(Request $request, Dealer $dealer): RedirectResponse
    {
        $data = $request->validate([
            'hepsiburada_merchant_id' => 'required|string|max:50',
            'hepsiburada_username' => 'nullable|string|max:255',
            'hepsiburada_password' => 'nullable|string|max:255',
        ]);

        $existing = $dealer->hepsiburada_credentials ?? [];
        $user = trim((string) ($data['hepsiburada_username'] ?? '')) ?: ($existing['username'] ?? null);
        $pass = trim((string) ($data['hepsiburada_password'] ?? '')) ?: ($existing['password'] ?? null);

        if (! $user || ! $pass) {
            return back()->with('error', 'Hepsiburada kullanıcı adı ve şifre gerekli.');
        }

        $dealer->update([
            'hepsiburada_merchant_id' => trim($data['hepsiburada_merchant_id']),
            'hepsiburada_credentials' => ['username' => $user, 'password' => $pass],
            'hepsiburada_last_error' => null,
        ]);

        try {
            app(\App\Services\HepsiburadaMarketplaceService::class)->testConnection($dealer->fresh());

            return back()->with('success', 'Hepsiburada bağlantısı kaydedildi ve test başarılı.');
        } catch (Throwable $e) {
            $dealer->update(['hepsiburada_last_error' => $e->getMessage()]);

            return back()->with('error', 'Kaydedildi ama test başarısız: '.$e->getMessage());
        }
    }

    public function testHepsiburada(Dealer $dealer): RedirectResponse
    {
        try {
            app(\App\Services\HepsiburadaMarketplaceService::class)->testConnection($dealer);
            $dealer->update(['hepsiburada_last_error' => null]);

            return back()->with('success', 'Hepsiburada bağlantısı başarılı.');
        } catch (Throwable $e) {
            $dealer->update(['hepsiburada_last_error' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Bu bayinin Trendyol ile ilgili bekleyen/başarısız işlerini temizler.
     *
     * Eskiden `jobs` tablosunun tamamı siliniyordu: diğer bayilerin ve XML
     * içe aktarmaların işleri de kayboluyordu. Artık yalnızca bu bayinin
     * Trendyol işleri (payload içindeki dealerId) silinir.
     */
    public function resetQueue(Dealer $dealer): RedirectResponse
    {
        TrendyolSendProgress::cancel($dealer->id, 'Kuyruk sıfırlandı.');

        try {
            Cache::lock('laravel_unique_job:trendyol-send-'.$dealer->id)->forceRelease();
            Cache::lock('laravel_unique_job:trendyol-run-'.$dealer->id)->forceRelease();
        } catch (Throwable) {
        }

        try {
            $deleted = $this->deleteDealerScopedJobs($dealer->id);
        } catch (Throwable $e) {
            return back()->with('error', 'Kuyruk temizlenemedi: '.$e->getMessage());
        }

        return back()->with('success', 'Bu bayinin Trendyol kuyruğu temizlendi ('.$deleted.' iş). Diğer bayilerin işleri korundu. Yeni gönderim başlatabilirsiniz.');
    }

    /** Payload içindeki dealerId'ye göre yalnız bu bayinin Trendyol işlerini siler. */
    private function deleteDealerScopedJobs(int $dealerId): int
    {
        $deleted = 0;
        $needle = 'dealerId";i:'.$dealerId.';';

        foreach (['jobs', 'failed_jobs'] as $table) {
            try {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            DB::table($table)
                ->where('queue', 'marketplace')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$deleted, $table, $needle): void {
                    $ids = [];
                    foreach ($rows as $row) {
                        $payload = json_decode((string) ($row->payload ?? ''), true);
                        if (! is_array($payload)) {
                            continue;
                        }
                        $display = (string) ($payload['displayName'] ?? ($payload['data']['commandName'] ?? ''));
                        if (! str_contains($display, 'Trendyol')) {
                            continue;
                        }
                        $command = (string) ($payload['data']['command'] ?? '');
                        if ($command !== '' && str_contains($command, $needle)) {
                            $ids[] = $row->id;
                        }
                    }

                    if ($ids !== []) {
                        $deleted += DB::table($table)->whereIn('id', $ids)->delete();
                    }
                });
        }

        return $deleted;
    }
}
