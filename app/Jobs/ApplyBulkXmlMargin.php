<?php

namespace App\Jobs;

use App\Models\PlatformSetting;
use App\Services\PricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyBulkXmlMargin implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 840;

    public int $tries = 1;

    public function __construct(
        public float $marginPercent,
        public bool $overrideTax = false,
        public ?int $sourceId = null,
        public ?int $userId = null,
    ) {
        // Ağır iş: tüm katalogda fiyat yeniden hesaplama (100'lük gruplar).
        // 'maintenance' kuyruğu — Trendyol işlerini bloke etmez.
        $this->onConnection(config('queue.default', 'database'));
        $this->onQueue('maintenance');
    }

    public function handle(PricingService $pricing): void
    {
        $started = microtime(true);

        $count = $pricing->bulkApplyXmlMargin(
            $this->marginPercent,
            $this->overrideTax,
            $this->sourceId,
        );

        Cache::forget('xml_feed_catalog');
        Cache::put('pricing_bulk_last_result', [
            'count' => $count,
            'margin' => $this->marginPercent,
            'source_id' => $this->sourceId,
            'finished_at' => now()->toIso8601String(),
            'seconds' => round(microtime(true) - $started, 2),
        ], now()->addHours(6));

        Log::info('ApplyBulkXmlMargin finished', [
            'count' => $count,
            'margin' => $this->marginPercent,
            'source_id' => $this->sourceId,
            'seconds' => round(microtime(true) - $started, 2),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ApplyBulkXmlMargin failed', [
            'margin' => $this->marginPercent,
            'source_id' => $this->sourceId,
            'message' => $exception?->getMessage(),
        ]);

        Cache::put('pricing_bulk_last_result', [
            'count' => 0,
            'error' => $exception?->getMessage() ?? 'Bilinmeyen hata',
            'margin' => $this->marginPercent,
            'source_id' => $this->sourceId,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(6));
    }
}
