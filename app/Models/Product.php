<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'source_id',
        'category_id',
        'stock_code',
        'barcode',
        'title',
        'slug',
        'brand',
        'description',
        'main_category',
        'sub_category',
        'category_path',
        'price',
        'cost_price',
        'xml_margin_percent',
        'sell_price',
        'min_margin_percent',
        'list_price',
        'tax_rate',
        'desi',
        'stock',
        'is_active',
        'is_featured',
        'show_on_homepage',
        'has_variants',
        'images',
        'attributes',
        'last_synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'xml_margin_percent' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'min_margin_percent' => 'decimal:2',
        'list_price' => 'decimal:2',
        'tax_rate' => 'integer',
        'desi' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'show_on_homepage' => 'boolean',
        'has_variants' => 'boolean',
        'images' => 'array',
        'attributes' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Gönderilebilecek ürünler: aktif ve stoğu olanlar.
     *
     * Tek kaynak: yönetim panelindeki "Tüm ürünleri gönder" sayacı, gönderim
     * işleri ve XML feed'i aynı ölçütü kullanır (sayılar birbirini tutsun).
     * Varyantlı üründe toplam varyant stoğu > 0 olmalıdır.
     */
    public function scopeSendable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $group): void {
                $group->where(function (Builder $plain): void {
                    $plain->where('has_variants', false)->where('stock', '>', 0);
                })->orWhere(function (Builder $variants): void {
                    $variants->where('has_variants', true)
                        ->whereHas('variants', fn (Builder $inner) => $inner->where('stock', '>', 0));
                });
            });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Varyantlı üründe toplam stok. Varyantlar önceden yüklenmişse ek sorgu
     * atmaz (liste/feed sayfalarında N+1 oluşuyordu).
     */
    public function getEffectiveStockAttribute(): int
    {
        $fallback = (int) ($this->attributes['stock'] ?? 0);

        if (! ($this->attributes['has_variants'] ?? false)) {
            return $fallback;
        }

        // Varyant tablosu/kolonu eksikse (eksik migration) sayfalar 500'e
        // düşmesin: ürünün kendi stok değerine dönüyoruz.
        try {
            if ($this->relationLoaded('variants')) {
                return (int) $this->variants->sum('stock');
            }

            return (int) ($this->variants()->sum('stock') ?? 0);
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * Bayiye görünen alış fiyatı ve önerilen satış fiyatı.
     *
     * @return array{sale: float, margin: float|null, retail: float|null}
     */
    public function priceForDealer(?Dealer $dealer = null): array
    {
        $pricing = app(PricingService::class);
        $sale = (float) ($this->sell_price ?? $this->price ?? 0);
        $margin = $dealer !== null
            ? (float) ($dealer->default_marketplace_margin ?? $pricing->defaultMarketplaceMargin())
            : null;

        return [
            'sale' => $sale,
            'margin' => $margin,
            'retail' => $margin !== null ? $pricing->calculateDealerRetailPrice($sale, $margin) : null,
        ];
    }
}
