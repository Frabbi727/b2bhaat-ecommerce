<?php

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;

class StockReservationService
{
    /**
     * Atomically verify and reserve stock using pessimistic row locking.
     *
     * @param  array<int, array{variation_id: int, quantity: int}>  $items
     *
     * @throws InsufficientStockException
     */
    public function reserveStock(array $items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $variationId = $item['variation_id'];
                $requestedQty = (int) $item['quantity'];

                if ($requestedQty <= 0) {
                    continue;
                }

                // Acquire exclusive pessimistic lock on the variation row
                /** @var ProductVariation|null $variation */
                $variation = ProductVariation::where('id', $variationId)
                    ->lockForUpdate()
                    ->first();

                if (! $variation) {
                    throw new InsufficientStockException(
                        message: "Product variation #{$variationId} was not found.",
                        variationId: $variationId
                    );
                }

                if ($variation->stock_quantity < $requestedQty) {
                    throw new InsufficientStockException(
                        message: "Insufficient stock for SKU: {$variation->sku}. Requested: {$requestedQty}, Available: {$variation->stock_quantity}.",
                        variationId: $variationId,
                        requestedQuantity: $requestedQty,
                        availableQuantity: $variation->stock_quantity
                    );
                }

                // Deduct stock quantity
                $variation->decrement('stock_quantity', $requestedQty);
            }
        });
    }

    /**
     * Release previously reserved stock back to inventory (e.g. upon payment failure or cancel).
     *
     * @param  array<int, array{variation_id: int, quantity: int}>  $items
     */
    public function releaseStock(array $items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $variationId = $item['variation_id'];
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                ProductVariation::where('id', $variationId)
                    ->lockForUpdate()
                    ->increment('stock_quantity', $quantity);
            }
        });
    }
}
