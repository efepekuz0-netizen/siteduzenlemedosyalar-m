<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Services\AutomationStatus;
use App\Services\DealerTrendyolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trendyol'da zaten oluşmuş ilanların fiyat ve stoğunu XML verisiyle eşitler.
 * Saatlik otomasyon tarafından kuyruğa bırakılır (komut içinde senkron
 * çalıştırıldığında uzun sürüyor ve zaman aşımına düşüyordu).
 */
class SyncDealerTrendyolInventory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 840;

    public int $tries = 2;

    public function __construct(
        public int $dealerId,
        public bool $automated = true,
    ) {
        // Saatlik stok/fiyat eşitlemesi uzun sürer (840 sn) — arka plan kuyruğu.
        $this->onConnection(config('queue.default', 'database'));
        $this->onQueue('maintenance');
    }

    public function handle(DealerTrendyolService $trendyol): void
    {
        $dealer = Dealer::query()->find($this->dealerId);
        if (! $dealer || ! $dealer->hasTrendyolCredentials()) {
            return;
        }

        try {
            $result = $trendyol->syncInventory($dealer);
            $updated = (int) ($result['updated'] ?? 0);

            $dealer->update(['trendyol_last_error' => null]);

            if ($this->automated) {
                AutomationStatus::record(
                    'trendyol_inventory',
                    AutomationStatus::STATUS_OK,
                    $dealer->company_name.': '.$updated.' ürünün fiyat/stoğu güncellendi.'
                );
            }

            Log::info('Trendyol fiyat/stok eşitleme tamamlandı', [
                'dealer_id' => $dealer->id,
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            $dealer->update(['trendyol_last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            if ($this->automated) {
                AutomationStatus::record(
                    'trendyol_inventory',
                    AutomationStatus::STATUS_ERROR,
                    $dealer->company_name.': '.$e->getMessage()
                );
            }

            Log::error('Trendyol fiyat/stok eşitleme başarısız', [
                'dealer_id' => $dealer->id,
                'error' => $e->getMessage(),
            ]);

            // Kuyrukta yeniden denenmesi için hatayı yükselt
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->automated) {
            return;
        }

        AutomationStatus::record(
            'trendyol_inventory',
            AutomationStatus::STATUS_ERROR,
            'Bayi #'.$this->dealerId.': '.($exception?->getMessage() ?? 'Bilinmeyen hata')
        );
    }
}
