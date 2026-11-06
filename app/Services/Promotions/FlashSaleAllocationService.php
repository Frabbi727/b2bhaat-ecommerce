<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Exceptions\FlashSaleUnavailableException;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\User;

/**
 * Checks, while the order is being written, that every flash-priced line is still allowed:
 * the campaign is live and neither its stock limit nor the buyer's own limit is passed.
 *
 * Must run inside the order transaction: each offer row stays locked until the order is saved,
 * so two buyers cannot both take the last units.
 */
class FlashSaleAllocationService
{
    /**
     * @param  array<int, array{product_id: int, quantity: int, flash_sale_product_id?: ?int}>  $items
     *
     * @throws FlashSaleUnavailableException
     */
    public function claim(User $customer, array $items): void
    {
        $requested = [];
        $productIds = [];
        foreach ($items as $item) {
            $offerId = $item['flash_sale_product_id'] ?? null;
            if ($offerId === null) {
                continue;
            }

            $offerId = (int) $offerId;
            $requested[$offerId] = ($requested[$offerId] ?? 0) + (int) $item['quantity'];
            $productIds[$offerId] = (int) $item['product_id'];
        }

        ksort($requested);

        foreach ($requested as $offerId => $quantity) {
            $offer = FlashSaleProduct::query()->whereKey($offerId)->lockForUpdate()->first();
            $title = (string) Product::query()->whereKey($offer?->product_id ?? $productIds[$offerId])->value('title');

            if ($offer === null || ! $offer->flashSale()->first()?->isLive()) {
                throw FlashSaleUnavailableException::ended($title);
            }

            if ($offer->stock_limit !== null) {
                $remaining = max(0, $offer->stock_limit - $offer->unitsSold());
                if ($quantity > $remaining) {
                    throw FlashSaleUnavailableException::onlyLeft($remaining, $title);
                }
            }

            if ($offer->per_customer_limit !== null
                && $offer->unitsBoughtBy((int) $customer->getKey()) + $quantity > $offer->per_customer_limit) {
                throw FlashSaleUnavailableException::customerLimit($offer->per_customer_limit, $title);
            }
        }
    }
}
