<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sınıf tabanlı sistem uç noktaları.
 *
 * Neden ayrı denetleyici: bu uç noktalar daha önce routes/web.php içinde
 * Closure olarak tanımlıydı ve **Closure rotalar `php artisan route:cache`
 * komutunu tamamen başarısız kılar**:
 *   "Unable to prepare route [/ping] for serialization. Uses Closure."
 * Üretimde route önbelleği alınamadığı için her istekte tüm rota dosyası
 * yeniden yorumlanıyordu. Artık üçü de denetleyiciye taşındı.
 */
class SystemController extends Controller
{
    /**
     * Ayrıntılı teşhis uç noktası (/health-detail).
     *
     * Sürüm ve hata mesajı sızdırdığı için yalnızca sunucunun kendisinden
     * (127.0.0.1/::1) ya da .env içindeki HEALTH_DETAIL_TOKEN ile erişilebilir.
     */
    public function healthDetail(Request $request)
    {
        $token = (string) config('bayiinet.health_token', '');
        $local = in_array((string) $request->ip(), ['127.0.0.1', '::1'], true) || app()->runningInConsole();
        $valid = $token !== '' && hash_equals($token, (string) $request->query('token', ''));

        if (! $local && ! $valid) {
            abort(404);
        }

        $out = ['ok' => true, 'php' => PHP_VERSION];

        try {
            DB::select('select 1');
            $out['db'] = 'ok';
        } catch (Throwable $e) {
            $out['ok'] = false;
            $out['db'] = $e->getMessage();
        }

        try {
            $out['sources_cols'] = Schema::hasTable('sources')
                ? Schema::getColumnListing('sources')
                : [];
        } catch (Throwable $e) {
            $out['sources_cols_error'] = $e->getMessage();
        }

        try {
            $out['dealers_with_credentials'] = Schema::hasTable('dealers')
                ? Dealer::query()->whereNotNull('trendyol_credentials')->count()
                : 0;
        } catch (Throwable $e) {
            $out['dealers_error'] = $e->getMessage();
        }

        return response()->json($out);
    }

    /** Basit canlılık kontrolü (/ping) — izleme araçları için. */
    public function ping()
    {
        return response('ok', 200)->header('Content-Type', 'text/plain');
    }

    /** Bayi paneli: başvuru durumu ekranı. */
    public function applicationStatus()
    {
        $user = request()->user();

        abort_unless($user && $user->isDealer() && $user->dealer, 404);

        return view('dealer.application', ['dealer' => $user->dealer]);
    }
}
