<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\FlashSaleSubmissionStatus;
use App\Exceptions\FlashSaleUnavailableException;
use App\Models\FlashSaleProduct;
use App\Models\OrderItem;
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

            if ($offer === null
                || $offer->status !== FlashSaleSubmissionStatus::Approved
                || ! $offer->flashSale()->first()?->isLive()) {
                throw FlashSaleUnavailableException::ended($title);
            }

            if ($offer->stock_limit !== null) {
                $remaining = max(0, (int) $offer->stock_limit - (int) $offer->units_sold);
                if ($quantity > $remaining) {
                    throw FlashSaleUnavailableException::onlyLeft($remaining, $title);
                }
            }

            if ($offer->per_customer_limit !== null
                && $offer->unitsBoughtBy((int) $customer->getKey()) + $quantity > $offer->per_customer_limit) {
                throw FlashSaleUnavailableException::customerLimit($offer->per_customer_limit, $title);
            }

            $offer->increment('units_sold', $quantity);
        }
    }

    /**
     * FS-1 / INV-7: give back the flash-sale units these order lines claimed, because their sale was undone
     * (cancelled, payment rejected or expired, reservation expired, returned). Offers are locked in id order. The
     * caller makes sure each parcel is undone once (status rules or a locked re-check).
     *
     * @param  iterable<OrderItem>  $orderItems
     */
    public function release(iterable $orderItems): void
    {
        $units = [];
        foreach ($orderItems as $item) {
            if ($item->flash_sale_product_id) {
                $units[(int) $item->flash_sale_product_id] = ($units[(int) $item->flash_sale_product_id] ?? 0) + (int) $item->quantity;
            }
        }

        ksort($units);

        foreach ($units as $offerId => $quantity) {
            $offer = FlashSaleProduct::whereKey($offerId)->lockForUpdate()->first();
            $offer?->update(['units_sold' => max(0, (int) $offer->units_sold - $quantity)]);
        }
    }
}
