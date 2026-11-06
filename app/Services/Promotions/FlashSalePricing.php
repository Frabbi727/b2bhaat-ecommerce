<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Models\FlashSaleProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place that decides which campaign offer prices a product right now.
 * Bound as a scoped singleton, so the per-product memo lives for one request only.
 */
class FlashSalePricing
{
    /**
     * Live offer (or null for "no offer") per product id, for the current request.
     *
     * @var array<int, FlashSaleProduct|null>
     */
    private array $offers = [];

    /**
     * The product's offer in a campaign that is live now, or null.
     */
    public function offerFor(Product $product): ?FlashSaleProduct
    {
        $productId = (int) $product->getKey();

        if (! array_key_exists($productId, $this->offers)) {
            $this->loadOffers([$productId]);
        }

        $offer = $this->offers[$productId];

        return $offer?->flashSale?->isLive() ? $offer : null;
    }

    /**
     * Load the live offers of many products at once so listing pages do not query per card.
     *
     * @param  iterable<Product>  $products
     */
    public function primeFor(iterable $products): void
    {
        $productIds = [];
        foreach ($products as $product) {
            $productId = (int) $product->getKey();
            if (! array_key_exists($productId, $this->offers)) {
                $productIds[$productId] = $productId;
            }
        }

        if ($productIds !== []) {
            $this->loadOffers(array_values($productIds));
        }
    }

    /**
     * Price of a retail cart line whose normal (tier) price is $tierPrice.
     *
     * @return array{unit_price: float, flash_sale_product_id: ?int, original_unit_price: ?float}
     */
    public function retailLinePrice(Product $product, float $tierPrice): array
    {
        $offer = $this->offerFor($product);

        if ($offer === null) {
            return [
                'unit_price' => $tierPrice,
                'flash_sale_product_id' => null,
                'original_unit_price' => null,
            ];
        }

        return [
            'unit_price' => $offer->priceFrom($tierPrice),
            'flash_sale_product_id' => (int) $offer->getKey(),
            'original_unit_price' => $tierPrice,
        ];
    }

    /**
     * Forget every remembered offer, e.g. after a campaign or one of its products changed.
     */
    public function flush(): void
    {
        $this->offers = [];
    }

    /**
     * Flush the memo of the current request's instance, if one was created.
     */
    public static function flushResolved(): void
    {
        if (app()->resolved(self::class)) {
            app(self::class)->flush();
        }
    }

    /**
     * @param  list<int>  $productIds
     */
    private function loadOffers(array $productIds): void
    {
        $found = FlashSaleProduct::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('flashSale', fn (Builder $sale): Builder => $sale->live())
            ->with('flashSale')
            ->orderBy('id')
            ->get()
            ->keyBy('product_id');

        foreach ($productIds as $productId) {
            $this->offers[$productId] = $found->get($productId);
        }
    }
}
