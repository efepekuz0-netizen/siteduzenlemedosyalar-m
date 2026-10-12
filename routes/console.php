<?php

use App\Services\AutomationStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Otomasyon
|--------------------------------------------------------------------------
| Tüm zamanlanmış işler durumunu AutomationStatus'a yazar; yönetim panelindeki
| "Otomasyon" ekranından son çalışma zamanı ve sonuçları görülebilir.
|
| Not: Bu görevlerin çalışabilmesi için `php artisan schedule:work` sürecinin
| ayakta olması gerekir (docker/start.sh içinde başlatılır; Render'da ayrı bir
| "Background Worker" servisi olarak çalıştırmak daha güvenlidir).
*/

// Zamanlayıcı nabzı: panel "zamanlayıcı çalışmıyor" uyarısını bununla verir.
Schedule::call(function (): void {
    AutomationStatus::heartbeat('scheduler');
})->everyFiveMinutes()->name('automation-heartbeat')->withoutOverlapping(10);

// Saatlik XML kaynak yenileme + bayi feed önbellek temizliği
Schedule::command('bayiinet:sync-dealers')
    ->hourly()
    ->withoutOverlapping(120)
    ->name('bayiinet-sync-dealers')
    ->onFailure(fn () => AutomationStatus::record('xml_import', AutomationStatus::STATUS_ERROR, 'Komut çalıştırılamadı.'));

// Saatlik Trendyol ürün gönderimi + fiyat/stok eşitleme
Schedule::command('bayiinet:sync-trendyol')
    ->hourly()
    ->withoutOverlapping(120)
    ->name('bayiinet-sync-trendyol')
    ->onFailure(fn () => AutomationStatus::record('trendyol_sync', AutomationStatus::STATUS_ERROR, 'Komut çalıştırılamadı.'));

// 15 dakikada bir: Trendyol'a gönderilmiş ama sonucu henüz okunamamış batch'ler
//
// ÖNEMLİ: Bu iş eskiden zamanlayıcı sürecinin İÇİNDE, tüm bayiler için API
// isteği yaparak çalışıyordu. Çok batch biriktiğinde zamanlayıcı bloke oluyor
// (nabız gecikiyor, saatlik görevler gecikiyor) ve panel "zamanlayıcı
// çalışmıyor" uyarısı veriyordu. Artık yalnızca tek bir sorgu yapılır ve her
// batch için doğrulama işi KUYRUĞA atılır; işi `bayiinet-queue` işçisi yapar.
Schedule::call(function (): void {
    AutomationStatus::heartbeat('scheduler');

    try {
        $pending = \App\Models\DealerTrendyolListing::query()
            ->where('status', 'sent')
            ->whereNotNull('batch_request_id')
            ->where('sent_at', '<', now()->subMinutes(2))
            ->select('dealer_id', 'batch_request_id')
            ->distinct()
            ->limit(50)
            ->get();

        foreach ($pending as $row) {
            \App\Jobs\VerifyTrendyolBatch::dispatch(
                (int) $row->dealer_id,
                (string) $row->batch_request_id,
            );
        }

        AutomationStatus::record(
            'trendyol_recheck',
            AutomationStatus::STATUS_OK,
            $pending->count().' batch doğrulaması kuyruğa alındı.'
        );
    } catch (\Throwable $e) {
        Log::warning('scheduled trendyol recheck', ['error' => $e->getMessage()]);
        AutomationStatus::record('trendyol_recheck', AutomationStatus::STATUS_ERROR, mb_substr($e->getMessage(), 0, 200));
    }
})->everyFifteenMinutes()->name('trendyol-recheck-sent')->withoutOverlapping(10);

// Kuyruk bakımı: eski başarısız iş kayıtlarını temizle
// Not: job_batches tablosu bu projede kullanılmıyor (migration yok),
// bu yüzden yalnızca failed_jobs temizlenir.
Schedule::command('queue:prune-failed --hours=168')->weekly()->name('queue-prune-failed');
