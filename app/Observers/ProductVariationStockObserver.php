<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProductVariation;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementContext;

/**
 * AR-08: writes a stock_movements row for every stock change, inside the same transaction, so a rolled-back order
 * leaves no movement behind. The reason comes from StockMovementContext.
 */
class ProductVariationStockObserver
{
    public function created(ProductVariation $variation): void
    {
        $stock = (int) $variation->stock_quantity;

        if ($stock !== 0) {
            $this->record($variation, $stock, StockMovement::REASON_OPENING);
        }
    }

    public function updated(ProductVariation $variation): void
    {
        if (! $variation->wasChanged('stock_quantity')) {
            return;
        }

        $change = (int) $variation->stock_quantity - (int) $variation->getOriginal('stock_quantity');

        if ($change !== 0) {
            $this->record($variation, $change, StockMovement::REASON_PRODUCT_EDIT);
        }
    }

    private function record(ProductVariation $variation, int $change, string $fallbackReason): void
    {
        $context = StockMovementContext::current();
        $reference = $context['reference'] ?? null;

        $movement = StockMovement::create([
            'product_variation_id' => $variation->id,
            'sku' => $variation->sku,
            'quantity_change' => $change,
            'quantity_after' => (int) $variation->stock_quantity,
            'reason' => $context['reason'] ?? $fallbackReason,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => auth()->id(),
            'note' => isset($context['note']) ? mb_substr($context['note'], 0, 255) : null,
        ]);

        StockMovementContext::remember($movement);
    }
}
