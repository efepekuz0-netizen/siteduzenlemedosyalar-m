<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Models\Product;
use App\Services\TrendyolSendProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendDealerTrendyolCatalog implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public bool $failOnTimeout = false;

    public const DEFAULT_BATCH_SIZE = 5;

    /**
     * @param  array<int, int>|null  $productIds
     * @param  array<int, array<string, mixed>>  $attributes
     */
    public function __construct(
        public int $dealerId,
        public ?array $productIds = null,
        public ?int $categoryId = null,
        public ?int $brandId = null,
        public array $attributes = [],
        public bool $onlyMissing = false,
    ) {
        $this->onConnection('database');
        $this->onQueue('marketplace');
    }

    public function uniqueId(): string
    {
        return 'trendyol-send-'.$this->dealerId;
    }

    public function handle(): void
    {
        if (TrendyolSendProgress::isCancelled($this->dealerId)) {
            return;
        }

        $dealer = Dealer::query()->find($this->dealerId);
        if (! $dealer || ! $dealer->hasTrendyolCredentials()) {
            TrendyolSendProgress::fail($this->dealerId, 'Bayi veya Trendyol API bilgisi yok.');

            return;
        }

        try {
            $query = Product::query()
                ->sendable()
                ->orderBy('id');

            if (is_array($this->productIds) && $this->productIds !== []) {
                $query->whereIn('id', $this->productIds);
            }

            $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            // Zaten Trendyol'da "created" olanları atla (yeniden gönderimde hız)
            // onlyMissing=true veya tüm katalog gönderiminde
            if ($ids !== []) {
                $already = \App\Models\DealerTrendyolListing::query()
                    ->where('dealer_id', $this->dealerId)
                    ->where('status', 'created')
                    ->whereIn('product_id', $ids)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                if ($already !== []) {
                    $ids = array_values(array_diff($ids, $already));
                }
            }

            $total = count($ids);

            if ($total === 0) {
                TrendyolSendProgress::fail($this->dealerId, "Gonderilecek yeni urun yok (hepsi zaten Trendyol da veya stok yok).");

                return;
            }

            $batchSize = 5;
            $chunks = array_chunk($ids, $batchSize);
            $totalBatches = count($chunks);

            TrendyolSendProgress::start(
                $this->dealerId,
                $total,
                $totalBatches,
                "0 / {$total} · {$totalBatches} parça (5'er ürün)"
            );

            foreach ($chunks as $index => $chunk) {
                SendDealerTrendyolBatch::dispatch(
                    $this->dealerId,
                    $chunk,
                    $this->categoryId,
                    $this->brandId,
                    $this->attributes,
                    $index + 1,
                    $totalBatches,
                )->delay(now()->addSeconds($index * 2));
            }

            Log::info('Trendyol catalog queued', [
                'dealer_id' => $this->dealerId,
                'total' => $total,
                'batches' => $totalBatches,
            ]);
        } catch (Throwable $e) {
            TrendyolSendProgress::fail($this->dealerId, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        TrendyolSendProgress::fail($this->dealerId, $exception?->getMessage() ?? 'Orchestrator hata');
    }
}
