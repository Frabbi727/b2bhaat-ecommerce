<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ProductVariation;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * INV-3: the seller changes stock by adding or removing units, never by typing a new total over the live figure.
 * The change is applied to the stock as it is at that moment, under a row lock, so units reserved by orders while
 * the seller was looking are never put back. Each adjustment is audited with its reason.
 */
class StockAdjustmentService
{
    /** @var array<string, string> */
    public const REASONS = [
        'received' => 'New stock received',
        'count_correction' => 'Stock count correction',
        'damaged' => 'Damaged or lost',
        'sold_elsewhere' => 'Sold outside B2Bhaat',
        'other' => 'Other',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Adds (positive) or removes (negative) units and returns the new stock.
     *
     * @throws DomainException when the change is zero, the reason is unknown, or stock would go below zero
     */
    public function adjust(ProductVariation $variation, int $change, string $reason, ?string $note = null): int
    {
        if ($change === 0) {
            throw new DomainException('Enter how many units to add or remove.');
        }

        if (! array_key_exists($reason, self::REASONS)) {
            throw new DomainException('Choose a reason for the change.');
        }

        return DB::transaction(function () use ($variation, $change, $reason, $note): int {
            $locked = ProductVariation::whereKey($variation->getKey())->lockForUpdate()->firstOrFail();
            $before = (int) $locked->stock_quantity;
            $after = $before + $change;

            if ($after < 0) {
                throw new DomainException("Only {$before} in stock for {$locked->sku}, so {$this->units(-$change)} can't be removed.");
            }

            $locked->update(['stock_quantity' => $after]);

            $this->audit->record([
                'module' => 'products',
                'action' => 'stock_adjusted',
                'description' => ($change > 0 ? 'Added ' : 'Removed ').$this->units(abs($change))." for {$locked->sku}: ".self::REASONS[$reason],
                'auditable_type' => ProductVariation::class,
                'auditable_id' => $locked->id,
                'record_label' => $locked->sku,
                'seller_id' => $locked->product?->seller_id,
                'old_values' => ['stock_quantity' => $before],
                'new_values' => ['stock_quantity' => $after],
                'changed_fields' => ['stock_quantity'],
                'status' => 'success',
                'metadata' => ['change' => $change, 'reason' => $reason, 'note' => $note],
                'sync' => true,
            ]);

            return $after;
        });
    }

    private function units(int $count): string
    {
        return $count === 1 ? '1 unit' : "{$count} units";
    }
}
