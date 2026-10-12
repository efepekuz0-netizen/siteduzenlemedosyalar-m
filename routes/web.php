<?php

use App\Http\Controllers\Admin\AutomationController as AdminAutomationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DealerController as AdminDealerController;
use App\Http\Controllers\Admin\DealerTrendyolController as AdminDealerTrendyolController;
use App\Http\Controllers\Admin\MarketplaceController as AdminMarketplaceController;
use App\Http\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Dealer\DashboardController as DealerDashboardController;
use App\Http\Controllers\Dealer\OrderController as DealerOrderController;
use App\Http\Controllers\Dealer\ProductController as DealerProductController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\XmlFeedController;
use Illuminate\Support\Facades\Route;



// Sistem uç noktaları (Closure DEĞİL — Closure rotalar route:cache'i bozar).
Route::get('/health-detail', [SystemController::class, 'healthDetail'])->name('system.health');
Route::get('/ping', [SystemController::class, 'ping'])->name('system.ping');

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::view('/gizlilik', 'pages.gizlilik')->name('pages.gizlilik');
Route::view('/mesafeli-satis', 'pages.mesafeli-satis')->name('pages.mesafeli');
Route::view('/iade', 'pages.iade')->name('pages.iade');
Route::view('/iletisim', 'pages.iletisim')->name('pages.contact');
Route::get('/urun/{product}', [HomeController::class, 'product'])->name('product.show');

Route::middleware('guest')->group(function () {
    Route::get('/giris', [AuthController::class, 'showLogin'])->name('login');
    // Brute-force ve spam kayıt denemelerine karşı hız sınırlaması
    Route::post('/giris', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('/bayilik-basvurusu', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/bayilik-basvurusu', [AuthController::class, 'register'])
        ->middleware('throttle:3,1')
        ->name('register.store');
});

Route::post('/cikis', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/xml/feed.xml', [XmlFeedController::class, 'publicFeed'])
    ->middleware(['auth', 'admin'])
    ->name('xml.public');
Route::get('/xml/{token}.xml', [XmlFeedController::class, 'dealerFeed'])->name('xml.dealer');

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/kaynaklar', [AdminSourceController::class, 'index'])->name('sources.index');
        Route::get('/kaynaklar/yeni', [AdminSourceController::class, 'create'])->name('sources.create');
        Route::get('/kaynaklar/{source}/duzenle', [AdminSourceController::class, 'edit'])->name('sources.edit');
        Route::post('/kaynaklar', [AdminSourceController::class, 'store'])->name('sources.store');
        Route::put('/kaynaklar/{source}', [AdminSourceController::class, 'update'])->name('sources.update');
        Route::post('/kaynaklar/{source}/xml', [AdminSourceController::class, 'upload'])->name('sources.upload');
        Route::post('/kaynaklar/{source}/guncelle', [AdminSourceController::class, 'refresh'])->name('sources.refresh');
        Route::delete('/kaynaklar/{source}', [AdminSourceController::class, 'destroy'])->name('sources.destroy');

        Route::get('/urunler', [AdminProductController::class, 'index'])->name('products.index');
        Route::get('/urunler/{product}/duzenle', [AdminProductController::class, 'edit'])->name('products.edit');
        Route::put('/urunler/{product}', [AdminProductController::class, 'update'])->name('products.update');
        Route::post('/urunler/cek', [AdminProductController::class, 'pullAll'])->name('products.pull');
        Route::post('/urunler/toplu-kar', [AdminProductController::class, 'bulkMargin'])->name('products.bulk-margin');

        Route::get('/bayiler', [AdminDealerController::class, 'index'])->name('dealers.index');
        Route::get('/bayiler/{dealer}', [AdminDealerController::class, 'show'])->name('dealers.show');
        Route::post('/bayiler/{dealer}/onayla', [AdminDealerController::class, 'approve'])->name('dealers.approve');
        Route::post('/bayiler/{dealer}/askiya-al', [AdminDealerController::class, 'suspend'])->name('dealers.suspend');
        Route::post('/bayiler/{dealer}/bakiye', [AdminDealerController::class, 'addBalance'])->name('dealers.balance');
        Route::put('/bayiler/{dealer}', [AdminDealerController::class, 'update'])->name('dealers.update');

        Route::get('/bayiler/{dealer}/trendyol', [AdminDealerTrendyolController::class, 'index'])->name('dealers.trendyol');
        Route::put('/bayiler/{dealer}/trendyol/baglanti', [AdminDealerTrendyolController::class, 'saveConnection'])->name('dealers.trendyol.connection');
        Route::post('/bayiler/{dealer}/trendyol/baglanti-kontrol', [AdminDealerTrendyolController::class, 'test'])->name('dealers.trendyol.test');
        Route::post('/bayiler/{dealer}/trendyol/gonder', [AdminDealerTrendyolController::class, 'send'])->name('dealers.trendyol.send');
        Route::post('/bayiler/{dealer}/trendyol/kuru-calisma', [AdminDealerTrendyolController::class, 'dryRun'])->name('dealers.trendyol.dry-run');
        Route::post('/bayiler/{dealer}/trendyol/sil', [AdminDealerTrendyolController::class, 'deleteProducts'])->name('dealers.trendyol.delete');
        Route::post('/bayiler/{dealer}/trendyol/durdur', [AdminDealerTrendyolController::class, 'cancelSend'])->name('dealers.trendyol.cancel');
        Route::post('/bayiler/{dealer}/trendyol/kuyruk-sifirla', [AdminDealerTrendyolController::class, 'resetQueue'])->name('dealers.trendyol.reset');
        Route::post('/bayiler/{dealer}/trendyol/sonuc', [AdminDealerTrendyolController::class, 'checkBatch'])->name('dealers.trendyol.batch');
        Route::post('/bayiler/{dealer}/trendyol/sonuc-tumu', [AdminDealerTrendyolController::class, 'recheckBatches'])->name('dealers.trendyol.recheck');
        Route::post('/bayiler/{dealer}/trendyol/fiyat-stok', [AdminDealerTrendyolController::class, 'syncInventory'])->name('dealers.trendyol.inventory');
        Route::get('/bayiler/{dealer}/hepsiburada', [AdminDealerTrendyolController::class, 'hepsiburada'])->name('dealers.hepsiburada');
        Route::put('/bayiler/{dealer}/hepsiburada/baglanti', [AdminDealerTrendyolController::class, 'saveHepsiburada'])->name('dealers.hepsiburada.connection');
        Route::post('/bayiler/{dealer}/hepsiburada/baglanti-kontrol', [AdminDealerTrendyolController::class, 'testHepsiburada'])->name('dealers.hepsiburada.test');

        Route::get('/siparisler', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/siparisler/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::put('/siparisler/{order}/durum', [AdminOrderController::class, 'updateStatus'])->name('orders.status');

        Route::get('/musteriler', [AdminOperationsController::class, 'customers'])->name('customers.index');
        Route::get('/kritik-stok', [AdminOperationsController::class, 'criticalStock'])->name('products.critical');
        Route::get('/sistem-sagligi', [AdminOperationsController::class, 'health'])->name('operations.health');
        Route::get('/kara-liste', [AdminOperationsController::class, 'blacklist'])->name('blacklist.index');
        Route::post('/kara-liste', [AdminOperationsController::class, 'storeBlacklist'])->name('blacklist.store');
        Route::delete('/kara-liste/{entry}', [AdminOperationsController::class, 'destroyBlacklist'])->name('blacklist.destroy');
        Route::get('/kategori-marka', [AdminOperationsController::class, 'taxonomy'])->name('taxonomy.index');
        Route::post('/kategori-marka/kategori', [AdminOperationsController::class, 'storeCategory'])->name('taxonomy.categories.store');
        Route::delete('/kategori-marka/kategori/{category}', [AdminOperationsController::class, 'destroyCategory'])->name('taxonomy.categories.destroy');
        Route::put('/kategori-marka/marka', [AdminOperationsController::class, 'updateBrand'])->name('taxonomy.brands.update');
        Route::get('/kar-fiyat', [AdminOperationsController::class, 'pricing'])->name('pricing.index');
        Route::put('/kar-fiyat', [AdminOperationsController::class, 'updatePricing'])->name('pricing.update');
        Route::get('/xml-disari-aktar', [AdminOperationsController::class, 'exportXml'])->name('export.xml');
        Route::get('/kayitlar', [AdminOperationsController::class, 'imports'])->name('logs.index');
        Route::get('/otomasyon', [AdminAutomationController::class, 'index'])->name('automation.index');
        Route::post('/otomasyon/xml', [AdminAutomationController::class, 'runXml'])->name('automation.xml');
        Route::post('/otomasyon/trendyol', [AdminAutomationController::class, 'runTrendyol'])->name('automation.trendyol');
        Route::get('/ayarlar', [AdminOperationsController::class, 'settings'])->name('settings.index');
        Route::put('/ayarlar', [AdminOperationsController::class, 'updateSettings'])->name('settings.update');
        Route::get('/pazaryeri', [AdminMarketplaceController::class, 'index'])->name('marketplace.index');
        Route::put('/pazaryeri/trendyol', [AdminMarketplaceController::class, 'updateTrendyol'])->name('marketplace.trendyol.update');
        Route::post('/pazaryeri/trendyol/baglanti-kontrol', [AdminMarketplaceController::class, 'testTrendyolConnection'])->name('marketplace.trendyol.test');
        Route::post('/pazaryeri/trendyol/urunleri-guncelle', [AdminMarketplaceController::class, 'syncTrendyolProducts'])->name('marketplace.trendyol.products.sync');
        Route::post('/pazaryeri/trendyol/siparisleri-cek', [AdminMarketplaceController::class, 'syncTrendyolOrders'])->name('marketplace.trendyol.orders.sync');
        Route::post('/pazaryeri/trendyol/siparisler/{order}/durum', [AdminMarketplaceController::class, 'updateTrendyolOrder'])->name('marketplace.trendyol.orders.status');
        Route::post('/pazaryeri/trendyol/senkronizasyon/{run}/batch-sonucu', [AdminMarketplaceController::class, 'checkTrendyolBatch'])->name('marketplace.trendyol.batch');
        Route::get('/ilanlar', [AdminOperationsController::class, 'announcements'])->name('announcements.index');
        Route::post('/ilanlar', [AdminOperationsController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::delete('/ilanlar/{announcement}', [AdminOperationsController::class, 'destroyAnnouncement'])->name('announcements.destroy');
    });

// Üye (bayi) paneli — bayilikxml tarzı kök yollar, /bayi öneki yok
Route::middleware('auth')->get('/basvuru-durumu', [SystemController::class, 'applicationStatus'])->name('dealer.application');

Route::middleware(['auth', 'dealer'])
    ->name('dealer.')
    ->group(function () {
        Route::get('/panel', [DealerDashboardController::class, 'index'])->name('dashboard');
        Route::get('/hesabim', [DealerDashboardController::class, 'account'])->name('account');
        Route::put('/hesabim/profil', [DealerDashboardController::class, 'updateProfile'])->name('account.profile');
        Route::put('/hesabim/sifre', [DealerDashboardController::class, 'updatePassword'])->name('account.password');
        Route::post('/hesabim/vergi-levhasi', [DealerDashboardController::class, 'uploadTaxDocument'])->name('account.tax');
        Route::get('/katalog', [DealerProductController::class, 'index'])->name('products.index');
        Route::get('/siparisler', [DealerOrderController::class, 'index'])->name('orders.index');
        Route::get('/siparisler/yeni', [DealerOrderController::class, 'create'])->name('orders.create');
        Route::post('/siparisler', [DealerOrderController::class, 'store'])->name('orders.store');
        Route::get('/siparisler/{order}', [DealerOrderController::class, 'show'])->name('orders.show');
        Route::post('/siparisler/{order}/kargo', [DealerOrderController::class, 'uploadCargo'])->name('orders.cargo');
    });

// Eski /bayi/* adreslerini yeni köklere yönlendir
Route::redirect('/bayi', '/panel', 301);
Route::redirect('/bayi/hesabim', '/hesabim', 301);
Route::redirect('/bayi/urunler', '/katalog', 301);
Route::redirect('/bayi/siparisler', '/siparisler', 301);
Route::redirect('/bayi/siparisler/yeni', '/siparisler/yeni', 301);
Route::redirect('/bayi/basvuru-durumu', '/basvuru-durumu', 301);
