<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\AutomationStatus;
use App\Services\XmlImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bir XML kaynağını arka planda içe aktarır.
 *
 * Daha önce içe aktarma HTTP isteği (ve zamanlanmış komut) içinde senkron
 * yapılıyordu: 15.000 ürünlük bir kaynakta istek zaman aşımına düşüyordu.
 */
class ImportSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 840;

    public int $tries = 1;

    /** Aynı kaynak için aynı anda iki içe aktarma çalışmasın. */
    public int $uniqueFor = 3600;

    public function __construct(
        public int $sourceId,
        public ?int $userId = null,
        public bool $automated = false,
    ) {
        // Ağır iş: 15.000 ürünlük XML içe aktarma 10 dakikaya kadar sürebilir.
        // 'maintenance' kuyruğuna alınır ki Trendyol gönderim/doğrulama işleri
        // (marketplace) ve panel işleri arkasında beklemesin.
        $this->onConnection(config('queue.default', 'database'));
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return 'xml-import-'.$this->sourceId;
    }

    public function handle(XmlImportService $importService): void
    {
        $source = Source::query()->find($this->sourceId);
        if ($source === null || ! $source->is_active) {
            return;
        }

        try {
            if ($source->type === 'url' && filled($source->url)) {
                $import = $importService->importFromUrl($source, $this->userId);
            } elseif (filled($source->file_path)) {
                $path = \Illuminate\Support\Facades\Storage::disk('local')->path($source->file_path);
                if (! is_file($path)) {
                    $path = storage_path('app/'.$source->file_path);
                }
                if (! is_file($path)) {
                    throw new \RuntimeException('XML dosyası bulunamadı: '.$source->file_path);
                }
                $import = $importService->importFromFile($source, $path, $this->userId);
            } else {
                return;
            }

            if ($import->status === 'completed') {
                $source->update([
                    'last_imported_at' => now(),
                    'last_product_count' => $source->products()->count(),
                    'last_error' => null,
                ]);

                if ($this->automated) {
                    AutomationStatus::record(
                        'xml_import',
                        AutomationStatus::STATUS_OK,
                        sprintf(
                            '%s: +%d yeni, %d güncellendi (%d ürün).',
                            $source->name,
                            (int) $import->created_count,
                            (int) $import->updated_count,
                            (int) $import->total_products
                        )
                    );
                }
            } else {
                $message = 'İçe aktarma tamamlanamadı: '.($import->log ?? 'bilinmeyen hata');
                $source->update(['last_error' => $message]);

                if ($this->automated) {
                    AutomationStatus::record('xml_import', AutomationStatus::STATUS_ERROR, $source->name.': '.$message);
                }
            }

            // Bayi feed önbellekleri bayi bazlı olarak yenilenir
            Cache::forget('xml_feed_catalog');
            Cache::forget('home_main_categories_v2');
            Cache::forget('admin_dash_stats_v2');
        } catch (Throwable $e) {
            $source->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);

            if ($this->automated) {
                AutomationStatus::record('xml_import', AutomationStatus::STATUS_ERROR, $source->name.': '.$e->getMessage());
            }

            Log::error('XML içe aktarma işi başarısız', [
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->automated) {
            return;
        }

        AutomationStatus::record(
            'xml_import',
            AutomationStatus::STATUS_ERROR,
            'Kaynak #'.$this->sourceId.': '.($exception?->getMessage() ?? 'Bilinmeyen hata')
        );
    }
}
