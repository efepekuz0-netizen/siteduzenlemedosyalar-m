<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AutomationStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Saatlik otomasyonun (XML senkronu + Trendyol gönderimi) gerçekten çalışıp
 * çalışmadığını panelden görebilmek için durum ekranı.
 */
class AutomationController extends Controller
{
    public function index(): View
    {
        $status = AutomationStatus::all();

        $icons = [
            'scheduler' => 'bi-clock-history',
            'xml_import' => 'bi-hdd-network',
            'trendyol_sync' => 'bi-send',
            'trendyol_inventory' => 'bi-arrow-left-right',
            'trendyol_recheck' => 'bi-check2-square',
        ];

        $jobs = [];
        foreach ($status as $key => $row) {
            if ($key === 'scheduler') {
                continue;
            }

            $minutesAgo = null;
            if ($row['ran_at'] !== null) {
                try {
                    $minutesAgo = (int) now()->diffInMinutes(new \DateTimeImmutable($row['ran_at']));
                } catch (\Throwable) {
                    $minutesAgo = null;
                }
            }

            $jobs[] = [
                'key' => $key,
                'title' => $row['label'],
                'schedule' => $row['schedule'],
                'icon' => $icons[$key] ?? 'bi-gear',
                'ran_at' => $row['ran_at'] ? date('d.m.Y H:i', strtotime($row['ran_at'])) : null,
                'minutes_ago' => $minutesAgo,
                'status' => $row['late'] && $row['status'] === AutomationStatus::STATUS_OK
                    ? AutomationStatus::STATUS_WARNING
                    : $row['status'],
                'message' => $row['message'],
            ];
        }

        $schedulerState = $status['scheduler'] ?? ['ran_at' => null, 'late' => true];

        return view('admin.automation.index', [
            'jobs' => $jobs,
            'scheduler' => [
                'alive' => AutomationStatus::schedulerAlive(),
                'last_seen' => $schedulerState['ran_at'] ? date('d.m.Y H:i', strtotime($schedulerState['ran_at'])) : null,
            ],
            'queue' => $this->queueStats(),
            'automation' => [
                'xml_sync' => (bool) config('bayiinet.automation.xml_sync', true),
                'trendyol_sync' => (bool) config('bayiinet.automation.trendyol_sync', true),
            ],
        ]);
    }

    /**
     * XML senkronunu hemen tetikle (zamanlayıcıyı beklemeden).
     */
    public function runXml(): RedirectResponse
    {
        Artisan::queue('bayiinet:sync-dealers');

        return back()->with('success', 'XML senkronu kuyruğa alındı; birkaç dakika içinde sonuç burada görünür.');
    }

    /**
     * Trendyol senkronunu hemen tetikle.
     */
    public function runTrendyol(): RedirectResponse
    {
        Artisan::queue('bayiinet:sync-trendyol');

        return back()->with('success', 'Trendyol senkronu kuyruğa alındı; birkaç dakika içinde sonuç burada görünür.');
    }

    /**
     * Kuyruk durumu.
     *
     * Not: Eskiden `done` alanı `jobs` tablosunda queue='marketplace' olan
     * kayıtları sayıyordu — bu "biten iş" değil, hâlâ BEKLEYEN Trendyol
     * işiydi ve ekranda "Trendyol kuyruğu" gibi bitmiş iş sayısı olarak
     * gösteriliyordu. Artık alanlar gerçek anlamlarıyla dönüyor.
     *
     * @return array{pending: int, marketplace: int, failed: int, worker_alive: bool, worker_last_seen: ?string, oldest_seconds: ?int, stuck: bool, advice: ?string}
     */
    private function queueStats(): array
    {
        try {
            $snapshot = \App\Services\QueueHealth::snapshot();

            return [
                'pending' => $snapshot['pending'],
                'marketplace' => $snapshot['pending_marketplace'],
                'failed' => $snapshot['failed'],
                'worker_alive' => $snapshot['worker_alive'],
                'worker_last_seen' => $snapshot['worker_last_seen'],
                'oldest_seconds' => $snapshot['oldest_seconds'],
                'stuck' => $snapshot['stuck'],
                'advice' => $snapshot['advice'],
            ];
        } catch (\Throwable) {
            return [
                'pending' => 0,
                'marketplace' => 0,
                'failed' => 0,
                'worker_alive' => false,
                'worker_last_seen' => null,
                'oldest_seconds' => null,
                'stuck' => false,
                'advice' => null,
            ];
        }
    }
}
