<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kuyruk işçisinin (`queue:work`) ve zamanlayıcının gerçekten çalışıp
 * çalışmadığını ölçer.
 *
 * Neden gerekli: XML içe aktarma, Trendyol gönderimi ve fiyat/stok eşitleme
 * tamamen kuyrukta çalışır. Render'dan kendi sunucuya (Oracle Cloud / Ubuntu)
 * taşındığında `queue:work` süreci kendiliğinden ayağa kalkmaz; panel
 * "gönderim kuyruğa alındı" dediği hâlde işler `jobs` tablosunda bekler ve
 * kullanıcı hiçbir ilerleme görmez. Bu sınıf, işçinin nabzını tutarak
 * sorunu panelde görünür hâle getirir.
 *
 * Nabız: işçi her döngüde (iş aldığında veya boşta beklerken) `touch()`
 * çağırır; bu metot en fazla dakikada bir önbelleğe zaman damgası yazar.
 */
class QueueHealth
{
    public const PING_KEY = 'bayiinet:queue-worker-ping';

    /**
     * Nabzın geçerlilik süresi (dakika).
     *
     * Uzun süren Trendyol gönderim işleri (en fazla 840 sn) işçiyi meşgul
     * eder ve bu sırada döngü dönmez; bu yüzden süre iş zaman aşımından
     * büyük seçildi (yanlış "işçi öldü" uyarısı vermemek için).
     */
    public const PING_TTL_MINUTES = 20;

    /** Aynı süreçte en fazla kaç saniyede bir önbelleğe yazılacağı. */
    private const WRITE_INTERVAL_SECONDS = 60;

    /** Bekleyen iş bu süreden uzun süredir bekliyorsa "takıldı" sayılır. */
    public const STUCK_AFTER_SECONDS = 600;

    private static float $lastTouch = 0.0;

    /**
     * İşçi sürecinden çağrılır (JobProcessing / WorkerIdle olayları).
     * Sıcak yolda (her 2 saniyede) veritabanına yazmamak için süreç içinde
     * zaman damgası tutulur; önbelleğe en fazla dakikada bir yazılır.
     */
    public static function touch(): void
    {
        $now = microtime(true);
        if ($now - self::$lastTouch < self::WRITE_INTERVAL_SECONDS) {
            return;
        }
        self::$lastTouch = $now;

        try {
            Cache::put(
                self::PING_KEY,
                now()->toIso8601String(),
                now()->addMinutes(self::PING_TTL_MINUTES),
            );
        } catch (Throwable) {
            // Önbellek yazılamıyorsa sessizce geç: nabız tutmak asla işi bozmamalı.
        }
    }

    public static function workerLastSeen(): ?string
    {
        try {
            $value = Cache::get(self::PING_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function workerAlive(): bool
    {
        $seen = self::workerLastSeen();
        if ($seen === null) {
            return false;
        }

        try {
            return abs(now()->diffInMinutes(new \DateTimeImmutable($seen))) <= self::PING_TTL_MINUTES;
        } catch (Throwable) {
            return false;
        }
    }

    public static function jobsTableExists(): bool
    {
        try {
            return Schema::hasTable('jobs');
        } catch (Throwable) {
            return false;
        }
    }

    public static function pendingCount(?string $queue = null): int
    {
        if (! self::jobsTableExists()) {
            return 0;
        }

        try {
            return (int) DB::table('jobs')
                ->when($queue !== null, fn ($q) => $q->where('queue', $queue))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function failedCount(): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }

            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** En eski bekleyen işin kaç saniyedir beklediği (yoksa null). */
    public static function oldestPendingSeconds(): ?int
    {
        if (! self::jobsTableExists()) {
            return null;
        }

        try {
            $oldest = DB::table('jobs')->orderBy('id')->value('available_at')
                ?? DB::table('jobs')->orderBy('id')->value('created_at');

            if ($oldest === null) {
                return null;
            }

            return max(0, (int) abs(now()->timestamp - (int) $oldest));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Panel ve teşhis ekranları için tek çağrıda kuyruk durumu.
     *
     * @return array{
     *   worker_alive: bool,
     *   worker_last_seen: ?string,
     *   pending: int,
     *   pending_default: int,
     *   pending_marketplace: int,
     *   pending_maintenance: int,
     *   failed: int,
     *   oldest_seconds: ?int,
     *   stuck: bool,
     *   advice: ?string
     * }
     */
    public static function snapshot(): array
    {
        $pending = self::pendingCount();
        $marketplace = self::pendingCount('marketplace');
        $maintenance = self::pendingCount('maintenance');
        $oldest = self::oldestPendingSeconds();
        $alive = self::workerAlive();

        $stuck = $pending > 0
            && ! $alive
            && $oldest !== null
            && $oldest >= self::STUCK_AFTER_SECONDS;

        $advice = null;
        if ($stuck) {
            $advice = 'Kuyrukta '.number_format($pending).' iş bekliyor ama işçi sinyali yok. '
                .'Sunucuda: sudo systemctl restart bayiinet-queue '
                .'(kuyruk işçisi çalışmıyorsa hiçbir gönderim ilerlemez)';
        } elseif ($pending > 0 && ! $alive) {
            $advice = 'Kuyrukta bekleyen iş var, işçi nabzı henüz okunmadı. '
                .'Kurulumdan sonra işçiyi başlatın: sudo systemctl start bayiinet-queue';
        } elseif ($pending > 300) {
            $advice = 'Kuyruk yoğun ('.number_format($pending).' iş). İşçi sayısını artırabilirsiniz.';
        }

        return [
            'worker_alive' => $alive,
            'worker_last_seen' => self::workerLastSeen(),
            'pending' => $pending,
            'pending_default' => max(0, $pending - $marketplace),
            'pending_marketplace' => $marketplace,
            'pending_maintenance' => $maintenance,
            'failed' => self::failedCount(),
            'oldest_seconds' => $oldest,
            'stuck' => $stuck,
            'advice' => $advice,
        ];
    }

    /** İnsan okur biçimde: "3 dk 20 sn". */
    public static function humanSeconds(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        if ($seconds < 60) {
            return $seconds.' sn';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' dk '.($seconds % 60).' sn';
        }

        return intdiv($seconds, 3600).' sa '.intdiv($seconds % 3600, 60).' dk';
    }
}
