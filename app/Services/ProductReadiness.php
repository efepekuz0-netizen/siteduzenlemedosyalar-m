<?php

namespace App\Services;

use App\Models\Product;
use Throwable;

/**
 * Gönderime hazır ürünlerin sayımı (tek SQL).
 *
 * Neden ayrı sınıf: aynı sayım hem yönetim panelindeki "Gönderim öncesi
 * kontrol" kartında, hem `bayiinet:teshis` komutunda kullanılıyor.
 *
 * ── Neden CAST(images AS CHAR) ──────────────────────────────────────────────
 * `images` kolonu MySQL/MariaDB'de **JSON** tipindedir (bkz. create_products_table).
 * Bir JSON kolonunu düz metinle karşılaştırmak (`images = ''`) MySQL 8'de
 *   ERROR 3141: Invalid JSON text in argument 1 to function cast_as_json:
 *               "The document is empty."
 * hatası verir; sorgu patlar, sayaçlar 0 görünür (panelde "Gönderime hazır
 * 0 ürün" ve Trendyol gönderimi durur). Eski kodda tam olarak bu vardı.
 *
 * JSON_LENGTH kullanmak da güvenli değildir: kolon TEXT'e düşürülmüş bir
 * veritabanında boş dize yine 3141 üretir.
 *
 * Bu yüzden karşılaştırma tip-bağımsız yapılır:
 *   TRIM(CAST(images AS CHAR)) IN ('', '[]', 'null')
 * Bu ifade MySQL (JSON veya TEXT), MariaDB ve SQLite'ta aynı sonucu verir.
 */
class ProductReadiness
{
    /**
     * @return array{total: int, missing_image: int, missing_barcode: int, missing_price: int}
     */
    public static function counts(): array
    {
        return self::countsWithError()['counts'];
    }

    /**
     * Teşhis komutu için: sayımın gerçekten çalışıp çalışmadığını da döndürür.
     *
     * @return array{counts: array<string, int>, error: string|null}
     */
    public static function countsWithError(): array
    {
        try {
            $row = Product::query()->sendable()->selectRaw(
                "COUNT(*) AS total,
                 SUM(CASE WHEN images IS NULL OR TRIM(CAST(images AS CHAR)) IN ('', '[]', 'null') THEN 1 ELSE 0 END) AS missing_image,
                 SUM(CASE WHEN barcode IS NULL OR barcode = '' THEN 1 ELSE 0 END) AS missing_barcode,
                 SUM(CASE WHEN COALESCE(sell_price, price, 0) <= 0 THEN 1 ELSE 0 END) AS missing_price"
            )->first();
        } catch (Throwable $e) {
            return ['counts' => ['total' => 0, 'missing_image' => 0, 'missing_barcode' => 0, 'missing_price' => 0], 'error' => $e->getMessage()];
        }

        return [
            'counts' => [
                'total' => (int) ($row->total ?? 0),
                'missing_image' => (int) ($row->missing_image ?? 0),
                'missing_barcode' => (int) ($row->missing_barcode ?? 0),
                'missing_price' => (int) ($row->missing_price ?? 0),
            ],
            'error' => null,
        ];
    }
}
