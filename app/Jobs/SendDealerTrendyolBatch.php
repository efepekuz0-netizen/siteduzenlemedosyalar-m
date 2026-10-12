<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Models\Product;
use App\Services\DealerTrendyolService;
use App\Services\TrendyolSendProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDealerTrendyolBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 45;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = false;

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, array<string, mixed>>  $attributes
     */
    public function __construct(
        public int $dealerId,
        public array $productIds,
        public ?int $categoryId,
        public ?int $brandId,
        public array $attributes,
        public int $batchIndex = 1,
        public int $totalBatches = 1,
    ) {
        $this->onConnection('database');
        $this->onQueue('marketplace');
    }

    public function handle(DealerTrendyolService $trendyol): void
    {
        $processed = max(1, count($this->productIds));

        try {
            if (TrendyolSendProgress::isCancelled($this->dealerId)) {
                TrendyolSendProgress::addBatch($this->dealerId, 0, 0, 0, [], []);

                return;
            }

            $dealer = Dealer::query()->find($this->dealerId);
            if (! $dealer || ! $dealer->hasTrendyolCredentials()) {
                TrendyolSendProgress::addBatch(
                    $this->dealerId,
                    $processed,
                    0,
                    $processed,
                    [],
                    ['Bayi veya Trendyol API bilgisi eksik.']
                );

                return;
            }

            // Form boşsa XML kaynağı varsayılanlarından doldur → sendFast yolu
            [$categoryId, $brandId] = $this->resolveDefaults(
                $this->categoryId,
                $this->brandId,
            );

            $result = $trendyol->sendFast(
                $dealer,
                $this->productIds,
                $categoryId,
                $brandId,
                $this->attributes,
            );

            // Yeni iletilenler batch doğrulamasında sayılır; burada yalnız
            // mevcut ilanın fiyat/stoğu tazelendiyse "gönderildi" yazılır.
            $sent = (int) ($result['updated'] ?? 0);
            $failed = (int) ($result['failed'] ?? 0);
            $errors = array_values(array_slice((array) ($result['errors'] ?? []), 0, 20));
            $batches = array_values((array) ($result['batches'] ?? []));

            TrendyolSendProgress::addBatch(
                $this->dealerId,
                $processed,
                $sent,
                $failed,
                $batches,
                $errors,
            );
        } catch (Throwable $e) {
            Log::warning('SendDealerTrendyolBatch', [
                'dealer_id' => $this->dealerId,
                'error' => $e->getMessage(),
            ]);
            TrendyolSendProgress::addBatch(
                $this->dealerId,
                $processed,
                0,
                $processed,
                [],
                [mb_substr($e->getMessage(), 0, 220)],
            );
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveDefaults(?int $categoryId, ?int $brandId): array
    {
        if (($categoryId && $categoryId > 0) && ($brandId && $brandId > 0)) {
            return [$categoryId, $brandId];
        }

        $products = Product::query()
            ->with('source')
            ->whereIn('id', $this->productIds)
            ->get();

        if (! $brandId || $brandId <= 0) {
            foreach ($products as $product) {
                $sid = (int) ($product->source?->default_trendyol_brand_id ?? 0);
                if ($sid > 0) {
                    $brandId = $sid;
                    break;
                }
            }
            if (! $brandId || $brandId <= 0) {
                $brandId = (int) config('bayiinet.trendyol.fallback_brand_id', 2613880);
            }
        }

        if (! $categoryId || $categoryId <= 0) {
            foreach ($products as $product) {
                $cid = (int) ($product->source?->default_trendyol_category_id ?? 0);
                if ($cid > 0) {
                    $categoryId = $cid;
                    break;
                }
            }
        }

        return [
            $categoryId && $categoryId > 0 ? $categoryId : null,
            $brandId && $brandId > 0 ? $brandId : null,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $msg = $exception?->getMessage() ?? 'Parça işlenemedi';
            if (str_contains(mb_strtolower($msg), 'attempted too many')) {
                $msg = 'Parça zaman aşımı. Kuyruğu sıfırlayıp XML kaynağına Marka No girerek tekrar deneyin.';
            }
            TrendyolSendProgress::addBatch(
                $this->dealerId,
                count($this->productIds),
                0,
                count($this->productIds),
                [],
                [mb_substr($msg, 0, 220)],
            );
        } catch (Throwable) {
        }
    }
}
