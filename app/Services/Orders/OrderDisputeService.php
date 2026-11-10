<?php

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderTrackingLog;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Promotions\FlashSaleAllocationService;
use App\Services\Settings\StoreConfigurationService;
use DomainException;
use Exception;
use Illuminate\Support\Facades\DB;

class OrderDisputeService
{
    public function __construct(
        protected StockReservationService $stockReservationService,
        protected EscrowLedgerService $escrowLedgerService,
        protected RefundRecorder $refundRecorder,
        protected OrderStatusReconciler $statusReconciler,
        protected PartialReturnService $partialReturns,
    ) {}

    /**
     * Cancel an unfulfilled sub-order consignment and release reserved variation stock.
     *
     * @throws Exception
     */
    public function cancelSubOrder(SubOrder $subOrder, string $reason = 'Cancelled by buyer'): void
    {
        $statusSlug = $subOrder->fulfillmentStatus?->slug;

        if (in_array($statusSlug, ['shipped', 'in_transit', 'out_for_delivery', 'delivered'])) {
            throw new Exception("Consignment #{$subOrder->sub_order_number} has already been dispatched with courier ({$subOrder->courier_name}). It cannot be cancelled. You may submit a return request upon delivery.");
        }

        $windowHours = app(StoreConfigurationService::class)->getOrderCancellationWindowHours();
        if ($subOrder->created_at && $subOrder->created_at->addHours($windowHours)->isPast()) {
            throw new Exception("Cancellation window of {$windowHours} hours has elapsed. Consignment #{$subOrder->sub_order_number} cannot be cancelled online. Please contact support.");
        }

        if ($statusSlug === 'cancelled') {
            return;
        }

        DB::transaction(function () use ($subOrder, $reason) {
            // Order before parcel, the same order payment verification and expiry lock in, so they can't deadlock
            // (Wave 1 review M-1).
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();

            // Re-read under a row lock: a concurrent cancel, delivery or return must not be cancelled again (final review I7)
            $subOrder = SubOrder::whereKey($subOrder->id)->lockForUpdate()->with('fulfillmentStatus', 'items')->firstOrFail();
            $currentSlug = $subOrder->fulfillmentStatus?->slug;
            if ($currentSlug === 'cancelled') {
                return;
            }
            if (! CourierStatusService::canTransition($currentSlug, 'cancelled')) {
                throw new Exception("Consignment #{$subOrder->sub_order_number} is {$currentSlug} and can no longer be cancelled.");
            }

            $cancelledFulfillment = FulfillmentStatus::where('slug', 'cancelled')->firstOrFail();

            // Customer already paid for this consignment → record what we owe back (PRA-027)
            $this->refundRecorder->recordIfPaid($subOrder, "Cancelled: {$reason}");

            // 1. Release reserved stock back to variations
            $stockItems = [];
            foreach ($subOrder->items as $item) {
                if ($item->variation_id) {
                    $stockItems[] = [
                        'variation_id' => $item->variation_id,
                        'quantity' => $item->quantity,
                    ];
                }
            }
            app(FlashSaleAllocationService::class)->release($subOrder->items);

            if (! empty($stockItems)) {
                $this->stockReservationService->releaseStock($stockItems);
            }

            // 2. Update sub-order fulfillment status
            $subOrder->update([
                'fulfillment_status_id' => $cancelledFulfillment->id,
            ]);

            // 3. Refund held escrow if funds were locked upon customer payment
            $this->escrowLedgerService->refundCancelledEscrow($subOrder, $reason);

            // 4. Create tracking log
            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $cancelledFulfillment->id,
                'title' => 'Consignment Cancelled',
                'description' => "Order cancelled: {$reason}. Reserved stock restored to catalog inventory.",
                'hub_location' => 'Merchant Hub',
                'logged_at' => now(),
            ]);

            // 5. The order's status follows its parcels (ORD-1)
            if ($subOrder->order) {
                $this->statusReconciler->reconcile($subOrder->order);
            }
        });
    }

    /**
     * Cancel every parcel of the order that has not left the seller yet. Parcels already on their way are kept (the
     * buyer can request a return after delivery), and the order's status then follows its parcels (ORD-1).
     *
     * @return array{cancelled: int, kept: int}
     *
     * @throws Exception when no parcel can be cancelled any more
     */
    public function cancelFullOrder(Order $order, string $reason = 'Cancelled by buyer'): array
    {
        return DB::transaction(function () use ($order, $reason): array {
            Order::whereKey($order->getKey())->lockForUpdate()->first();

            $parcels = $order->subOrders()->with('fulfillmentStatus')->get();
            $cancellable = $parcels->filter(fn (SubOrder $subOrder): bool => in_array($subOrder->fulfillmentStatus?->slug, ['unfulfilled', 'processing'], true));
            $kept = $parcels->filter(fn (SubOrder $subOrder): bool => $subOrder->fulfillmentStatus?->slug !== 'cancelled')->count() - $cancellable->count();

            if ($cancellable->isEmpty()) {
                throw new Exception("Order #{$order->order_number} cannot be cancelled: everything in it is already on its way, delivered or cancelled. You can request a return after delivery.");
            }

            foreach ($cancellable as $subOrder) {
                $this->cancelSubOrder($subOrder, $reason);
            }

            $this->statusReconciler->reconcile($order);

            return ['cancelled' => $cancellable->count(), 'kept' => $kept];
        });
    }

    /**
     * Submit return / dispute request for a delivered sub-order.
     *
     * @param array{
     *     reason: string,
     *     requested_action: string,
     *     description: string,
     *     image_proof_url?: ?string,
     *     items?: array<int|string, int|string|null>
     * } $data  items: order item id => units coming back (PAY-4); left out, the whole parcel
     *
     * @throws Exception
     */
    public function submitReturnRequest(SubOrder $subOrder, User $customer, array $data): ReturnRequest
    {
        if ($subOrder->fulfillmentStatus?->slug !== 'delivered') {
            throw new Exception("Returns are only applicable for delivered consignments. Current status: {$subOrder->fulfillmentStatus?->name}.");
        }

        if ($subOrder->order?->customer_id !== $customer->id) {
            throw new Exception('Unauthorized. You can only request returns for orders placed under your account.');
        }

        // Returns close when the seller is paid (escrow release), so a refund is never owed on money already paid out
        $windowHours = app(StoreConfigurationService::class)->getEscrowHoldingPeriodHours();
        $deliveredAt = $subOrder->deliveredAt();
        if ($deliveredAt !== null && $deliveredAt->copy()->addHours($windowHours)->isPast()) {
            throw new Exception("Returns must be requested within {$windowHours} hours of delivery. This consignment was delivered on {$deliveredAt->format('d M Y, g:i A')}.");
        }

        return DB::transaction(function () use ($subOrder, $customer, $data) {
            // One open request at a time, so two returns can never claim the same units (PAY-4)
            SubOrder::whereKey($subOrder->id)->lockForUpdate()->first();
            if ($subOrder->returnRequests()->where('status', ReturnRequest::STATUS_PENDING)->exists()) {
                throw new Exception('A return for this parcel is already waiting for the seller. You can ask about other items once it is decided.');
            }

            $items = isset($data['items']) ? $this->partialReturns->validatedQuantities($subOrder, $data['items']) : [];

            $returnRequest = ReturnRequest::create([
                'sub_order_id' => $subOrder->id,
                'customer_id' => $customer->id,
                'reason' => $data['reason'],
                'requested_action' => $data['requested_action'] ?? 'refund',
                'description' => $data['description'],
                'image_proof_url' => $data['image_proof_url'] ?? null,
                'status' => ReturnRequest::STATUS_PENDING,
            ]);

            foreach ($items as $orderItemId => $quantity) {
                $returnRequest->items()->create(['order_item_id' => $orderItemId, 'quantity' => $quantity]);
            }

            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $subOrder->fulfillment_status_id,
                'title' => 'Return / Dispute Requested',
                'description' => "Buyer initiated return ({$data['requested_action']}) for: {$data['reason']}. Escrow investigation initiated.",
                'hub_location' => 'Customer Dispute Center',
                'logged_at' => now(),
            ]);

            return $returnRequest;
        });
    }

    /**
     * Process resolution for a return request.
     */
    public function resolveReturnRequest(ReturnRequest $returnRequest, string $status, ?string $note = null): void
    {
        DB::transaction(function () use ($returnRequest, $status, $note) {
            // Same lock order as the courier path (order, parcel, then request), so the two never deadlock and a
            // courier return can't land between the check below and the settlement (review R-1)
            $subOrder = SubOrder::whereKey($returnRequest->sub_order_id)->firstOrFail();
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();
            $subOrder = SubOrder::whereKey($subOrder->id)->lockForUpdate()->with('fulfillmentStatus')->firstOrFail();

            // A request is resolved once; re-resolving would restock and refund twice
            $returnRequest = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
            if ($returnRequest->status !== ReturnRequest::STATUS_PENDING) {
                return;
            }

            // A refund is only owed on a parcel the buyer still has; one already returned was refunded in full
            if ($status === ReturnRequest::STATUS_APPROVED && $returnRequest->requested_action === 'refund'
                && $subOrder->fulfillmentStatus?->slug !== 'delivered') {
                throw new DomainException('This parcel is no longer with the buyer (it is marked '.($subOrder->fulfillmentStatus?->name ?? 'changed').'), so this return can\'t be approved. Nothing was changed.');
            }

            // Review R-4: a request for "everything" after earlier item returns means the items still with the buyer
            if ($status === ReturnRequest::STATUS_APPROVED && $returnRequest->requested_action === 'refund'
                && ! $returnRequest->items()->exists() && $this->partialReturns->hasItemReturns($subOrder)) {
                foreach (array_filter($this->partialReturns->remainingQuantities($subOrder)) as $orderItemId => $quantity) {
                    $returnRequest->items()->create(['order_item_id' => $orderItemId, 'quantity' => $quantity]);
                }
            }

            $returnRequest->update([
                'status' => $status,
                'seller_note' => $note,
                'resolved_at' => now(),
            ]);

            // Replacement: the sale stands (seller ships a replacement), so the consignment stays delivered and
            // its escrow hold is released on schedule; no restock, no refund (final review I1)
            if ($status === ReturnRequest::STATUS_APPROVED && $returnRequest->requested_action !== 'refund') {
                OrderTrackingLog::create([
                    'sub_order_id' => $returnRequest->sub_order_id,
                    'fulfillment_status_id' => $returnRequest->subOrder->fulfillment_status_id,
                    'title' => 'Replacement Approved by Supplier',
                    'description' => 'Replacement approved: '.($note ?? $returnRequest->reason).'. The seller will ship a replacement item.',
                    'hub_location' => 'Merchant Hub',
                    'logged_at' => now(),
                ]);

                return;
            }

            // PAY-4: some items, or the rest after earlier item returns, are settled item by item
            if ($status === ReturnRequest::STATUS_APPROVED && $this->partialReturns->settlesItemByItem($returnRequest)) {
                $this->partialReturns->settle($returnRequest, 'Return approved: '.($note ?? $returnRequest->reason));

                return;
            }

            if ($status === ReturnRequest::STATUS_APPROVED) {
                $returnedStatus = FulfillmentStatus::where('slug', 'returned')->first();
                if ($returnedStatus) {
                    $returnRequest->subOrder->update([
                        'fulfillment_status_id' => $returnedStatus->id,
                    ]);

                    // 1. Reverse financial settlement if refund was requested
                    if ($returnRequest->requested_action === 'refund') {
                        $this->escrowLedgerService->reverseReturnedSubOrder(
                            $returnRequest->subOrder,
                            $note ?? 'Customer return approved by seller'
                        );

                        $this->refundRecorder->recordIfPaid($returnRequest->subOrder, 'Return approved: '.($note ?? $returnRequest->reason));
                    }

                    // 2. Restock returned goods to variation catalog inventory
                    $stockItems = [];
                    foreach ($returnRequest->subOrder->items as $item) {
                        if ($item->variation_id) {
                            $stockItems[] = [
                                'variation_id' => $item->variation_id,
                                'quantity' => $item->quantity,
                            ];
                        }
                    }

                    if (! empty($stockItems)) {
                        $this->stockReservationService->releaseStock($stockItems);
                    }
                    app(FlashSaleAllocationService::class)->release($returnRequest->subOrder->items);

                    // 3. Create tracking timeline log
                    OrderTrackingLog::create([
                        'sub_order_id' => $returnRequest->sub_order_id,
                        'fulfillment_status_id' => $returnedStatus->id,
                        'title' => 'Return Approved by Supplier',
                        'description' => "Return approved: {$note}. Settlement reversed and inventory restored.",
                        'hub_location' => 'Merchant Hub',
                        'logged_at' => now(),
                    ]);

                    // 4. The order's status follows its parcels (ORD-1)
                    if ($returnRequest->subOrder->order) {
                        $this->statusReconciler->reconcile($returnRequest->subOrder->order);
                    }
                }
            }
        });
    }
}
