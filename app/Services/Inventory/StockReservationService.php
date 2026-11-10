<?php

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariation;
use App\Services\Settings\StoreConfigurationService;
use Illuminate\Support\Facades\DB;

class StockReservationService
{
    public function __construct(
        protected StoreConfigurationService $config
    ) {}

    /**
     * Atomically verify and reserve stock using pessimistic row locking.
     *
     * @param  array<int, array{variation_id: int, quantity: int}>  $items
     *
     * @throws InsufficientStockException
     */
    public function reserveStock(array $items): void
    {
        $allowBackorders = $this->config->isBackorderAllowed();

        DB::transaction(function () use ($items, $allowBackorders) {
            foreach (self::mergedInLockOrder($items) as $variationId => $requestedQty) {
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

                if (! $allowBackorders && $variation->stock_quantity < $requestedQty) {
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
            foreach (self::mergedInLockOrder($items) as $variationId => $quantity) {
                $variation = ProductVariation::where('id', $variationId)->lockForUpdate()->first();
                $variation?->increment('stock_quantity', $quantity);
            }
        });
    }

    /**
     * PAY-6: one entry per variation (repeats added together, so stock is checked against the total), lowest id
     * first. Every path that locks several stock rows uses this order, so two of them can never deadlock.
     *
     * @param  iterable<array{variation_id: int, quantity: int}>  $items
     * @return array<int, int> variation id => quantity
     */
    public static function mergedInLockOrder(iterable $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            if ($quantity > 0) {
                $merged[(int) $item['variation_id']] = ($merged[(int) $item['variation_id']] ?? 0) + $quantity;
            }
        }

        ksort($merged);

        return $merged;
    }
}
