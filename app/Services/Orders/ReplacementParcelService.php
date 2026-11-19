<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Exceptions\InsufficientStockException;
use App\Models\FulfillmentStatus;
use App\Models\OrderItem;
use App\Models\OrderTrackingLog;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\SubOrder;
use App\Services\Inventory\StockMovementContext;
use App\Services\Inventory\StockReservationService;
use DomainException;

/**
 * WA-ORD-03: an approved replacement is a ৳0 follow-up parcel from the same seller. It takes the new units from stock,
 * is packed, dispatched and tracked like any parcel, moves no money, and holds the original parcel's payment to the
 * seller until it has been delivered (ReleaseDeliveredEscrowCommand).
 */
class ReplacementParcelService
{
    public function __construct(
        private readonly StockReservationService $stock,
        private readonly PartialReturnService $partialReturns,
    ) {}

    /**
     * Runs inside the caller's transaction, with the original parcel locked.
     *
     * @throws DomainException when there is not enough stock to send the replacement
     */
    public function createFor(ReturnRequest $returnRequest, ?string $note = null): SubOrder
    {
        $original = $returnRequest->subOrder()->with('items')->firstOrFail();
        $quantities = $this->quantities($returnRequest, $original);
        if ($quantities === []) {
            throw new DomainException('There is nothing left in this parcel to replace.');
        }

        $replacement = SubOrder::create([
            'parent_order_id' => $original->parent_order_id,
            'seller_id' => $original->seller_id,
            'replaces_sub_order_id' => $original->id,
            'replaces_return_request_id' => $returnRequest->id,
            'sub_order_number' => $original->sub_order_number.'-R'.(SubOrder::where('replaces_sub_order_id', $original->id)->count() + 1),
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id'),
            'subtotal' => 0,
            'commission_amount' => 0,
            'shipping_amount' => 0,
        ]);

        $stockItems = [];
        foreach ($original->items as $item) {
            $quantity = $quantities[$item->id] ?? 0;
            if ($quantity <= 0) {
                continue;
            }

            OrderItem::create([
                'sub_order_id' => $replacement->id,
                'product_id' => $item->product_id,
                'variation_id' => $item->variation_id,
                'sales_channel_id' => $item->sales_channel_id,
                'unit_price' => 0,
                'original_unit_price' => $item->original_unit_price ?? $item->unit_price,
                'quantity' => $quantity,
                'total_price' => 0,
                'commission_rate' => 0,
            ]);

            if ($item->variation_id) {
                $stockItems[] = ['variation_id' => $item->variation_id, 'quantity' => $quantity];
            }
        }

        try {
            StockMovementContext::run(StockMovement::REASON_REPLACEMENT, $replacement, fn () => $this->stock->reserveStock($stockItems), 'Replacement for parcel #'.$original->sub_order_number);
        } catch (InsufficientStockException $e) {
            throw new DomainException('Not enough stock to send a replacement ('.$e->getMessage().'). Add stock first, or ask the buyer to take a refund instead. Nothing was changed.');
        }

        foreach ([$original, $replacement] as $parcel) {
            OrderTrackingLog::create([
                'sub_order_id' => $parcel->id,
                'fulfillment_status_id' => $parcel->fulfillment_status_id,
                'title' => $parcel->is($original) ? 'Replacement Approved by Supplier' : 'Replacement parcel created',
                'description' => $parcel->is($original)
                    ? 'Replacement approved: '.($note ?? $returnRequest->reason).". New items will be sent in parcel #{$replacement->sub_order_number}."
                    : "Free replacement for parcel #{$original->sub_order_number}. The seller will pack and send it.",
                'hub_location' => 'Merchant Hub',
                'logged_at' => now(),
            ]);
        }

        return $replacement;
    }

    /**
     * @return array<int, int> order item id => units to send again
     */
    private function quantities(ReturnRequest $returnRequest, SubOrder $original): array
    {
        $lines = $returnRequest->items()->get(['order_item_id', 'quantity']);
        if ($lines->isNotEmpty()) {
            return $lines->mapWithKeys(fn ($line): array => [(int) $line->order_item_id => (int) $line->quantity])->all();
        }

        return array_filter($this->partialReturns->remainingQuantities($original));
    }
}
