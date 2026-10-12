<?php

namespace App\Providers;

use App\Services\QueueHealth;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Tek merkezden şifre kuralı: kayıt, profil güncelleme ve yönetici
        // oluşturma aynı kuralı kullanır.
        Password::defaults(fn (): Password => Password::min(8));

        // Kuyruk işçisinin nabzı: panel "işçi çalışıyor mu?" sorusunu
        // tahminle değil, kayıtla yanıtlar (bkz. QueueHealth).
        Event::listen(JobProcessing::class, static fn () => QueueHealth::touch());
        Event::listen(WorkerIdle::class, static fn () => QueueHealth::touch());
    }
}
