<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\XmlFeedController;
use App\Models\BalanceTransaction;
use App\Models\BlacklistEntry;
use App\Models\Category;
use App\Models\DealerAnnouncement;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\XmlImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperationsController extends Controller
{
    public function customers(Request $request)
    {
        $query = Order::query();
        if ($search = trim((string) $request->get('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }
        $customers = $query
            ->selectRaw('customer_name, customer_phone, customer_email, customer_city, count(*) as order_count, sum(total) as total_spent, max(created_at) as last_order_at')
            ->groupBy('customer_name', 'customer_phone', 'customer_email', 'customer_city')
            ->latest('last_order_at')
            ->paginate(30)->withQueryString();

        return view('admin.customers.index', compact('customers'));
    }

    public function criticalStock()
    {
        $threshold = (int) PlatformSetting::read('critical_stock_threshold', '5');
        $products = Product::with('variants', 'source')
            ->where('is_active', true)
            ->where(function ($query) use ($threshold) {
                $query->where(function ($plain) use ($threshold) {
                    $plain->where('has_variants', false)->where('stock', '<=', $threshold);
                })->orWhere(function ($variant) use ($threshold) {
                    $variant->where('has_variants', true)
                        ->whereHas('variants', fn ($stock) => $stock->where('stock', '<=', $threshold));
                });
            })
            ->orderBy('stock')
            ->paginate(40);

        return view('admin.products.critical-stock', compact('products', 'threshold'));
    }

    public function blacklist()
    {
        $entries = BlacklistEntry::latest()->paginate(30);

        return view('admin.blacklist.index', compact('entries'));
    }

    public function storeBlacklist(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'customer'])],
            'value' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);
        $data['value'] = trim($data['value']);

        BlacklistEntry::query()->updateOrCreate(
            ['type' => $data['type'], 'value' => $data['value']],
            ['reason' => $data['reason'] ?? null, 'created_by' => auth()->id()]
        );

        return back()->with('success', 'Kara liste kaydı güncellendi.');
    }

    public function destroyBlacklist(BlacklistEntry $entry)
    {
        $entry->delete();

        return back()->with('success', 'Kara liste kaydı silindi.');
    }

    public function taxonomy()
    {
        $categories = Category::withCount('products')->orderBy('name')->paginate(30);
        $brands = Product::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('brand, count(*) as products_count')
            ->groupBy('brand')
            ->orderBy('brand')
            ->get();

        return view('admin.catalog.taxonomy', compact('categories', 'brands'));
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'full_path' => 'nullable|string|max:500',
            'parent_id' => 'nullable|exists:categories,id',
        ]);
        $data['full_path'] = $data['full_path'] ?? $data['name'];
        $data['slug'] = Str::slug($data['full_path']).'-'.Str::random(5);
        $data['is_active'] = true;

        Category::create($data);

        return back()->with('success', 'Kategori oluşturuldu.');
    }

    public function updateBrand(Request $request)
    {
        $data = $request->validate([
            'current_brand' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
        ]);

        Product::query()->where('brand', $data['current_brand'])->update(['brand' => $data['brand']]);
        Cache::forget('xml_feed_catalog');

        return back()->with('success', 'Marka adı ürün kataloğunda güncellendi.');
    }

    public function destroyCategory(Category $category)
    {
        $category->delete();

        return back()->with('success', 'Kategori silindi.');
    }

    public function pricing()
    {
        $settings = [
            'xml_margin_percent' => PlatformSetting::read('xml_margin_percent', '15'),
            'min_margin_percent' => PlatformSetting::read('min_margin_percent', '5'),
            'default_marketplace_margin' => PlatformSetting::read('default_marketplace_margin', '20'),
            'xml_tax_rate' => PlatformSetting::read('xml_tax_rate', '20'),
            'xml_prices_include_tax' => PlatformSetting::read('xml_prices_include_tax', '0'),
        ];
        $products = Product::query()->where('is_active', true)->latest()->paginate(30);
        $lastBulk = Cache::get('pricing_bulk_last_result');

        return view('admin.pricing.index', compact('settings', 'products', 'lastBulk'));
    }

    public function updatePricing(Request $request)
    {
        $data = $request->validate([
            'xml_margin_percent' => 'required|numeric|min:0|max:500',
            'min_margin_percent' => 'required|numeric|min:0|max:500',
            'default_marketplace_margin' => 'required|numeric|min:0|max:500',
            'xml_tax_rate' => 'required|numeric|min:0|max:100',
            'xml_prices_include_tax' => 'nullable|boolean',
            'apply_to_all' => 'nullable|boolean',
        ]);

        PlatformSetting::write('xml_margin_percent', $data['xml_margin_percent']);
        PlatformSetting::write('min_margin_percent', $data['min_margin_percent']);
        PlatformSetting::write('default_marketplace_margin', $data['default_marketplace_margin']);
        PlatformSetting::write('xml_tax_rate', $data['xml_tax_rate']);
        PlatformSetting::write('xml_prices_include_tax', $request->boolean('xml_prices_include_tax') ? '1' : '0');

        $msg = 'Kar oranları kaydedildi.';

        if ($request->boolean('apply_to_all')) {
            // Arka planda uygula — sayfa anında yanıt verir, uzun bekleme olmaz
            \App\Jobs\ApplyBulkXmlMargin::dispatch(
                (float) $data['xml_margin_percent'],
                true,
                null,
                auth()->id()
            );
            $msg .= ' Tüm ürünlere uygulama arka planda başlatıldı. Birkaç saniye / dakika içinde tamamlanır.';
        }

        Cache::forget('xml_feed_catalog');

        \App\Services\AdminAudit::log('pricing.update', 'Kâr & fiyatlama ayarları güncellendi.', [
            'keys' => array_keys($data),
            'apply_to_all' => $request->boolean('apply_to_all'),
        ]);

        return back()->with('success', $msg);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'critical_stock_threshold' => 'required|integer|min:0|max:100000',
            'profit_margin' => 'nullable|numeric|min:0|max:500',
            'xml_margin_percent' => 'nullable|numeric|min:0|max:500',
            'min_margin_percent' => 'nullable|numeric|min:0|max:500',
            'default_marketplace_margin' => 'nullable|numeric|min:0|max:500',
            'xml_tax_rate' => 'nullable|numeric|min:0|max:100',
            'xml_prices_include_tax' => 'nullable|boolean',
            'company_name' => 'nullable|string|max:255',
        ]);

        foreach ($data as $key => $value) {
            if ($value !== null) {
                PlatformSetting::write($key, $value);
            }
        }

        PlatformSetting::write('xml_prices_include_tax', $request->boolean('xml_prices_include_tax') ? '1' : '0');

        // Platform kâr oranı boş bırakılırsa XML kâr oranıyla aynı tutulur
        if (! array_key_exists('profit_margin', $data) || $data['profit_margin'] === null) {
            if (array_key_exists('xml_margin_percent', $data) && $data['xml_margin_percent'] !== null) {
                PlatformSetting::write('profit_margin', $data['xml_margin_percent']);
            }
        }

        if (array_key_exists('xml_margin_percent', $data) && $data['xml_margin_percent'] !== null) {
            \App\Jobs\ApplyBulkXmlMargin::dispatch(
                (float) $data['xml_margin_percent'],
                array_key_exists('xml_tax_rate', $data),
                null,
                auth()->id()
            );
        }
        Cache::forget('xml_feed_catalog');

        \App\Services\AdminAudit::log('settings.update', 'Panel ayarları güncellendi.', ['keys' => array_keys($data)]);

        return back()->with('success', 'Panel ayarları kaydedildi. Kar oranı değiştiyse ürün fiyatları arka planda güncelleniyor.');
    }

    public function settings()
    {
        $settings = [
            'critical_stock_threshold' => PlatformSetting::read('critical_stock_threshold', '5'),
            'profit_margin' => app(\App\Services\PricingService::class)->profitMargin(),
            'xml_margin_percent' => PlatformSetting::read('xml_margin_percent', '15'),
            'min_margin_percent' => PlatformSetting::read('min_margin_percent', '5'),
            'default_marketplace_margin' => PlatformSetting::read('default_marketplace_margin', '20'),
            'company_name' => PlatformSetting::read('company_name', 'BayiXML'),
            'xml_tax_rate' => PlatformSetting::read('xml_tax_rate', '20'),
            'xml_prices_include_tax' => PlatformSetting::read('xml_prices_include_tax', '0'),
        ];

        return view('admin.settings.index', compact('settings'));
    }

    public function exportXml(XmlFeedController $feed)
    {
        return response($feed->publicFeed()->getContent(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bayixml-urunler.xml"',
        ]);
    }

    public function imports()
    {
        $imports = XmlImport::with(['source', 'user'])->latest()->paginate(40);
        $transactions = BalanceTransaction::with(['dealer', 'creator'])->latest()->take(30)->get();

        return view('admin.logs.index', compact('imports', 'transactions'));
    }

    public function announcements()
    {
        $announcements = DealerAnnouncement::latest()->paginate(20);

        return view('admin.announcements.index', compact('announcements'));
    }

    public function storeAnnouncement(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['created_by'] = auth()->id();

        DealerAnnouncement::create($data);

        \App\Services\AdminAudit::log('announcement.store', 'Bayi duyurusu yayınlandı: '.$data['title']);

        return back()->with('success', 'Bayi duyurusu yayınlandı.');
    }

    public function destroyAnnouncement(DealerAnnouncement $announcement)
    {
        $announcement->delete();

        return back()->with('success', 'Duyuru silindi.');
    }

    /**
     * Sistem sağlığı / teşhis ekranı.
     *
     * Yarım kalmış migration'lar, eksik kolonlar ve anasayfa sorgusundaki
     * hataları (500) admin panelinde görünür kılar.
     */
    public function health()
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks[] = ['label' => 'Veritabanı bağlantısı', 'ok' => true, 'detail' => DB::connection()->getDatabaseName()];
            $dbOk = true;
        } catch (\Throwable $e) {
            $checks[] = ['label' => 'Veritabanı bağlantısı', 'ok' => false, 'detail' => $e->getMessage()];
            $dbOk = false;
        }

        $tables = [
            'users', 'dealers', 'products', 'product_variants', 'sources',
            'orders', 'order_items', 'jobs', 'failed_jobs', 'cache', 'platform_settings',
        ];
        $tableStatus = [];
        foreach ($tables as $table) {
            $exists = $dbOk ? Schema::hasTable($table) : false;
            $tableStatus[$table] = $exists;
            if (! $exists) {
                $checks[] = ['label' => "Tablo: {$table}", 'ok' => false, 'detail' => 'Tablo bulunamadı (migration eksik olabilir)'];
            }
        }

        // Not: `orders.total_amount` bu şemada hiç olmadı (modelden kaldırıldı);
        // burada beklenen kolon olarak tutulduğu için ekran sürekli yanlış
        // alarm veriyordu. Beklenen kolonlar gerçek şemayla eşitlendi.
        $expectedColumns = [
            'products' => ['is_active', 'is_featured', 'show_on_homepage', 'has_variants', 'last_synced_at', 'main_category', 'category_path', 'sell_price', 'stock', 'images', 'title', 'stock_code'],
            'product_variants' => ['product_id', 'stock', 'variant_price', 'variant_stock', 'variant_images', 'barcode'],
            'orders' => ['dealer_id', 'status', 'total', 'cargo_pdf_path'],
            'dealers' => ['trendyol_seller_id', 'trendyol_credentials', 'default_marketplace_margin', 'auto_sync_enabled'],
            'sources' => ['default_trendyol_brand_id', 'default_trendyol_category_id'],
        ];
        $missingColumns = [];
        if ($dbOk) {
            foreach ($expectedColumns as $table => $columns) {
                if (! ($tableStatus[$table] ?? false)) {
                    $missingColumns[$table] = ['(tablo yok)'];
                    continue;
                }
                $missing = [];
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = $column;
                    }
                }
                if ($missing !== []) {
                    $missingColumns[$table] = $missing;
                    $checks[] = [
                        'label' => "Eksik kolon: {$table}",
                        'ok' => false,
                        'detail' => implode(', ', $missing).' — migration çalıştırılmalı',
                    ];
                }
            }
        }

        // Anasayfa / ürün kartı duman testi: 500 hatalarının kaynağını gösterir.
        $tests = [];

        try {
            $total = Product::query()->where('is_active', true)->count();
            $tests[] = ['label' => 'Ürün sorgusu', 'ok' => true, 'detail' => "Aktif ürün: ".number_format($total)];
        } catch (\Throwable $e) {
            $tests[] = ['label' => 'Ürün sorgusu', 'ok' => false, 'detail' => $e->getMessage()];
            $total = 0;
        }

        try {
            $cats = Product::query()->where('is_active', true)
                ->whereNotNull('main_category')->where('main_category', '!=', '')
                ->distinct()->pluck('main_category')->values();
            $tests[] = ['label' => 'Kategori sorgusu', 'ok' => true, 'detail' => $cats->count().' kategori'];
        } catch (\Throwable $e) {
            $tests[] = ['label' => 'Kategori sorgusu', 'ok' => false, 'detail' => $e->getMessage()];
        }

        try {
            $featuredCount = Schema::hasColumn('products', 'is_featured')
                ? Product::query()->where('is_active', true)->where('is_featured', true)->count()
                : 0;
            $tests[] = ['label' => 'Öne çıkan sorgusu', 'ok' => true, 'detail' => $featuredCount.' ürün'];
        } catch (\Throwable $e) {
            $tests[] = ['label' => 'Öne çıkan sorgusu', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Varyant stok toplama (anasayfa kartında çağrılıyor)
        try {
            $variantProduct = Product::query()->where('has_variants', true)->first();
            if ($variantProduct === null) {
                $tests[] = ['label' => 'Varyant stok toplama', 'ok' => true, 'detail' => 'Varyantlı ürün yok, test atlandı'];
            } else {
                $stock = $variantProduct->effective_stock;
                $tests[] = ['label' => 'Varyant stok toplama', 'ok' => true, 'detail' => "Ürün #{$variantProduct->id} → stok {$stock}"];
            }
        } catch (\Throwable $e) {
            $tests[] = ['label' => 'Varyant stok toplama', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Kart render testi: hangi ürün patlıyorsa gösterir
        $cardErrors = [];
        try {
            $samples = Product::query()->where('is_active', true)->orderByDesc('id')->take(24)->get();
            foreach ($samples as $sample) {
                try {
                    view('partials.product-card', ['product' => $sample])->render();
                } catch (\Throwable $e) {
                    $cardErrors[] = [
                        'product_id' => $sample->id,
                        'title' => $sample->title,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                }
            }
            $tests[] = [
                'label' => 'Ürün kartı render testi',
                'ok' => $cardErrors === [],
                'detail' => $cardErrors === []
                    ? $samples->count().' ürün sorunsuz render edildi'
                    : count($cardErrors).' üründe hata (aşağıda listeli)',
            ];
        } catch (\Throwable $e) {
            $tests[] = ['label' => 'Ürün kartı render testi', 'ok' => false, 'detail' => $e->getMessage()];
        }

        // Kuyruk ve zamanlayıcı: işçi nabzı dahil (kendi sunucuya geçişte
        // en sık kırılan yer queue:work sürecinin çalışmamasıdır).
        $queue = ['pending' => 0, 'failed' => 0];
        try {
            if ($tableStatus['jobs'] ?? false) {
                $queue['pending'] = DB::table('jobs')->count();
            }
            if ($tableStatus['failed_jobs'] ?? false) {
                $queue['failed'] = DB::table('failed_jobs')->count();
            }
        } catch (\Throwable $e) {
            // kuyruk tablosu okunamazsa sessizce geç
        }

        try {
            $queueHealth = \App\Services\QueueHealth::snapshot();
            $queue['worker_alive'] = $queueHealth['worker_alive'];
            $queue['worker_last_seen'] = $queueHealth['worker_last_seen'];
            $queue['oldest_seconds'] = $queueHealth['oldest_seconds'];
            $queue['stuck'] = $queueHealth['stuck'];
            $queue['advice'] = $queueHealth['advice'];
        } catch (\Throwable $e) {
            $queue['worker_alive'] = false;
            $queue['worker_last_seen'] = null;
            $queue['oldest_seconds'] = null;
            $queue['stuck'] = false;
            $queue['advice'] = null;
        }

        $failedJobs = collect();
        if (($tableStatus['failed_jobs'] ?? false) && $queue['failed'] > 0) {
            try {
                $failedJobs = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get();
            } catch (\Throwable $e) {
                $failedJobs = collect();
            }
        }

        $automation = [
            'xml' => (bool) config('bayiinet.automation.xml_sync', false),
            'trendyol' => (bool) config('bayiinet.automation.trendyol_sync', false),
        ];
        try {
            $heartbeat = [
                'scheduler_alive' => \App\Services\AutomationStatus::schedulerAlive(),
                'jobs' => \App\Services\AutomationStatus::all(),
            ];
        } catch (\Throwable $e) {
            $heartbeat = ['scheduler_alive' => false, 'jobs' => [], 'error' => $e->getMessage()];
        }

        return view('admin.operations.health', [
            'checks' => $checks,
            'tableStatus' => $tableStatus,
            'missingColumns' => $missingColumns,
            'tests' => $tests,
            'cardErrors' => $cardErrors,
            'queue' => $queue,
            'failedJobs' => $failedJobs,
            'automation' => $automation,
            'heartbeat' => $heartbeat,
        ]);
    }

}
