<?php

namespace App\Jobs;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceSyncRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TrendyolMarketplaceService;
use App\Services\TrendyolPriceCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncTrendyolCatalog implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 840;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
        // Saatlik katalog taraması (840 sn) — arka plan kuyruğu.
        $this->onConnection(config('queue.default', 'database'));
        $this->onQueue('maintenance');
    }

    public function handle(
        TrendyolMarketplaceService $marketplace,
        TrendyolPriceCalculator $priceCalculator,
    ): void {
        $run = MarketplaceSyncRun::query()
            ->with(['connection', 'source'])
            ->findOrFail($this->runId);

        $run->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $connection = $run->connection;
        $source = $run->source;
        if ($connection === null || $source === null) {
            throw new \RuntimeException('Pazaryeri bağlantısı veya XML kaynağı artık mevcut değil.');
        }

        $options = $connection->options ?? [];
        $prefix = (string) ($options['barcode_prefix'] ?? 'SS13-');
        $catalog = $this->approvedListingIndex($connection, $marketplace, $prefix);
        $updates = [];
        $ambiguousBarcodes = [];
        $owners = [];
        $skipped = 0;
        $total = 0;
        $matchedExisting = 0;

        Product::query()
            ->with('variants')
            ->where('source_id', $source->id)
            ->chunkById(200, function ($products) use (

                $catalog,
                $priceCalculator,
                $prefix,
                $options,
                &$updates,
                &$ambiguousBarcodes,
                &$owners,
                &$skipped,
                &$total,
                &$matchedExisting,
            ): void {
                foreach ($products as $product) {
                    $variants = $product->variants;
                    if ($product->has_variants && $variants->isNotEmpty()) {
                        foreach ($variants as $variant) {
                            $total++;
                            $this->addUpdate(
                                $product,
                                $variant,
                                $catalog,
                                $priceCalculator,
                                $prefix,
                                $options,
                                $updates,
                                $ambiguousBarcodes,
                                $owners,
                                $skipped,
                                $matchedExisting,
                            );
                        }
                    } else {
                        $total++;
                        $this->addUpdate(
                            $product,
                            null,
                            $catalog,
                            $priceCalculator,
                            $prefix,
                            $options,
                            $updates,
                            $ambiguousBarcodes,
                            $owners,
                            $skipped,
                            $matchedExisting,
                        );
                    }
                }
            });

        foreach (array_keys($ambiguousBarcodes) as $barcode) {
            unset($updates[$barcode]);
        }

        $batchRequestIds = [];
        foreach (array_chunk(array_values($updates), 1000) as $batch) {
            $response = $marketplace->updatePriceAndInventory($connection, $batch);
            $batchRequestId = $response['batchRequestId'] ?? null;
            if (! is_string($batchRequestId) || $batchRequestId === '') {
                throw new \UnexpectedValueException('Trendyol stok/fiyat isteği bir batchRequestId döndürmedi.');
            }

            $batchRequestIds[] = $batchRequestId;
            $run->update([
                'batch_request_ids' => $batchRequestIds,
                'matched_count' => count($updates),
            ]);
        }

        $run->update([
            'status' => 'completed',
            'total_count' => $total,
            'matched_count' => count($updates),
            'skipped_count' => $skipped + count($ambiguousBarcodes),
            'failed_count' => 0,
            'batch_request_ids' => $batchRequestIds,
            'message' => $updates !== []
                ? 'Eşleşen mevcut ilanların fiyat ve stok güncellemesi Trendyol kuyruğuna gönderildi.'
                : ($matchedExisting > 0
                    ? 'Mevcut ilanlar eşleşti; gönderilecek değişiklik yok veya fiyatlandırma sınırları nedeniyle atlandı. Yeni ilan oluşturulmadı.'
                    : 'Onaylı mevcut ilanlarla eşleşme bulunamadı; yeni ilan oluşturulmadı.'),
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        MarketplaceSyncRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }

    private function approvedListingIndex(
        MarketplaceConnection $connection,
        TrendyolMarketplaceService $marketplace,
        string $prefix,
    ): array {
        $index = [];
        $response = $marketplace->approvedInventoryPage($connection, ['page' => 0, 'size' => 100]);
        $this->indexInventoryResponse($response, $index, $prefix);

        $totalPages = min(max((int) ($response['totalPages'] ?? 1), 1), 100);
        for ($page = 1; $page < $totalPages; $page++) {
            $response = $marketplace->approvedInventoryPage($connection, ['page' => $page, 'size' => 100]);
            $this->indexInventoryResponse($response, $index, $prefix);
            usleep(150_000);
        }

        $hasMoreThanPageLimit = (int) ($response['totalElements'] ?? 0) > 10000
            || (int) ($response['totalPages'] ?? 0) > 100;
        $nextPageToken = $hasMoreThanPageLimit ? ($response['nextPageToken'] ?? null) : null;
        if ($hasMoreThanPageLimit && (! is_string($nextPageToken) || $nextPageToken === '')) {
            throw new \UnexpectedValueException('Trendyol ürün sayfalaması 10.000 ürün sınırından sonra devam imleci döndürmedi.');
        }

        $seenTokens = [];
        while (is_string($nextPageToken) && $nextPageToken !== '') {
            if (isset($seenTokens[$nextPageToken]) || count($seenTokens) >= 10000) {
                throw new \UnexpectedValueException('Trendyol ürün sayfalaması güvenli biçimde ilerleyemedi.');
            }
            $seenTokens[$nextPageToken] = true;

            $response = $marketplace->approvedInventoryPage($connection, [
                'size' => 100,
                'nextPageToken' => $nextPageToken,
            ]);
            $this->indexInventoryResponse($response, $index, $prefix);
            $nextPageToken = $response['nextPageToken'] ?? null;
            usleep(150_000);
        }

        return $index;
    }

    private function indexInventoryResponse(array $response, array &$index, string $prefix): void
    {
        foreach (($response['content'] ?? []) as $product) {
            $variants = $product['variants'] ?? [$product];
            foreach ($variants as $variant) {
                $barcode = trim((string) ($variant['barcode'] ?? ''));
                if ($barcode === '') {
                    continue;
                }

                $index['barcodes'][$barcode] = [
                    'quantity' => $variant['quantity'] ?? null,
                    'salePrice' => $variant['salePrice'] ?? null,
                    'listPrice' => $variant['listPrice'] ?? null,
                ];

                foreach ([$barcode, (string) ($variant['stockCode'] ?? '')] as $identity) {
                    foreach ($this->identityKeys($identity, $prefix) as $key) {
                        $index['identities'][$key][$barcode] = true;
                    }
                }
            }
        }
    }

    private function addUpdate(
        Product $product,
        ?ProductVariant $variant,
        array $catalog,
        TrendyolPriceCalculator $priceCalculator,
        string $prefix,
        array $options,
        array &$updates,
        array &$ambiguousBarcodes,
        array &$owners,
        int &$skipped,
        int &$matchedExisting,
    ): void {
        $candidateCodes = array_filter([
            $variant?->barcode,
            $variant?->sku,
            $product->barcode,
            $product->stock_code,
        ], fn (?string $code): bool => $code !== null && trim($code) !== '');
        $barcode = $this->findExistingBarcode($candidateCodes, $catalog, $prefix);

        if ($barcode === null) {
            $skipped++;

            return;
        }
        $matchedExisting++;

        $owner = $product->id.':'.($variant?->id ?? 'product');
        if (isset($owners[$barcode]) && $owners[$barcode] !== $owner) {
            $ambiguousBarcodes[$barcode] = true;

            return;
        }
        $owners[$barcode] = $owner;

        $cost = $variant !== null && $variant->variant_price !== null
            ? (float) $variant->variant_price
            : (float) $product->price + (float) ($variant?->price_diff ?? 0);
        $salePrice = $priceCalculator->calculate(
            $cost,
            (float) ($product->desi ?: ($options['default_desi'] ?? 5)),
            $options,
        );

        if ($salePrice === null) {
            $skipped++;

            return;
        }

        $quantity = min(max((int) ($variant?->stock ?? $product->stock), 0), 20000);
        $currentInventory = $catalog['barcodes'][$barcode] ?? [];
        if (
            is_numeric($currentInventory['quantity'] ?? null)
            && (int) $currentInventory['quantity'] === $quantity
            && is_numeric($currentInventory['salePrice'] ?? null)
            && abs((float) $currentInventory['salePrice'] - $salePrice) < 0.01
            && is_numeric($currentInventory['listPrice'] ?? null)
            && abs((float) $currentInventory['listPrice'] - $salePrice) < 0.01
        ) {
            $skipped++;

            return;
        }

        $updates[$barcode] = [
            'barcode' => $barcode,
            'quantity' => $quantity,
            'salePrice' => $salePrice,
            'listPrice' => $salePrice,
        ];
    }

    private function findExistingBarcode(array $candidateCodes, array $catalog, string $prefix): ?string
    {
        foreach ($candidateCodes as $candidateCode) {
            $matches = [];
            foreach ($this->identityKeys($candidateCode, $prefix) as $key) {
                $matches = [...$matches, ...array_keys($catalog['identities'][$key] ?? [])];
            }

            $matches = array_values(array_unique($matches));
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                return null;
            }
        }

        return null;
    }

    private function identityKeys(string $value, string $prefix): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $bare = str_starts_with(mb_strtolower($value), mb_strtolower($prefix))
            ? substr($value, strlen($prefix))
            : $value;
        $prefixed = str_starts_with(mb_strtolower($bare), mb_strtolower($prefix))
            ? $bare
            : $prefix.$bare;

        return array_values(array_unique(array_map(
            mb_strtolower(...),
            [$value, $bare, $prefixed],
        )));
    }
}
