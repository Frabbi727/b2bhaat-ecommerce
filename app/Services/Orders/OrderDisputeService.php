<?php

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderTrackingLog;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\Orders\ReturnEscalated;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockMovementContext;
use App\Services\Inventory\StockReservationService;
use App\Services\Promotions\FlashSaleAllocationService;
use App\Services\Settings\StoreConfigurationService;
use DomainException;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class OrderDisputeService
{
    public function __construct(
        protected StockReservationService $stockReservationService,
        protected EscrowLedgerService $escrowLedgerService,
        protected RefundRecorder $refundRecorder,
        protected OrderStatusReconciler $statusReconciler,
        protected PartialReturnService $partialReturns,
        protected ParcelCancellationService $parcelCancellation,
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

        // AR-16: once the seller has packed it, the buyer asks the seller (or support) instead
        if ($statusSlug === 'processing') {
            throw new Exception("Consignment #{$subOrder->sub_order_number} has already been packed by the seller, so it can't be cancelled online. Please contact the seller or B2Bhaat support.");
        }

        try {
            $this->parcelCancellation->cancel($subOrder, ParcelCancellationService::BY_BUYER, $reason, onlyFrom: ['unfulfilled']);
        } catch (DomainException $e) {
            throw new Exception($e->getMessage(), previous: $e);
        }
    }

    /**
     * Cancel every parcel of the order the seller has not packed yet. Packed parcels and parcels already on their way
     * are kept (the buyer can request a return after delivery), and the order's status then follows its parcels
     * (ORD-1, AR-16).
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
            $cancellable = $parcels->filter(fn (SubOrder $subOrder): bool => $subOrder->fulfillmentStatus?->slug === 'unfulfilled');
            $kept = $parcels->filter(fn (SubOrder $subOrder): bool => $subOrder->fulfillmentStatus?->slug !== 'cancelled')->count() - $cancellable->count();

            if ($cancellable->isEmpty()) {
                throw new Exception("Order #{$order->order_number} cannot be cancelled: everything in it is already packed, on its way, delivered or cancelled. You can contact the seller about a packed parcel, or request a return after delivery.");
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
            if ($subOrder->returnRequests()->whereIn('status', ReturnRequest::OPEN_STATUSES)->exists()) {
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
    public function resolveReturnRequest(ReturnRequest $returnRequest, string $status, ?string $note = null, bool $escalationDecision = false): void
    {
        DB::transaction(function () use ($returnRequest, $status, $note, $escalationDecision) {
            // Same lock order as the courier path (order, parcel, then request), so the two never deadlock and a
            // courier return can't land between the check below and the settlement (review R-1)
            $subOrder = SubOrder::whereKey($returnRequest->sub_order_id)->firstOrFail();
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();
            $subOrder = SubOrder::whereKey($subOrder->id)->lockForUpdate()->with('fulfillmentStatus')->firstOrFail();

            // A request is resolved once; re-resolving would restock and refund twice
            $returnRequest = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
            // 3.4: an escalated request is decided only by an admin, through decideEscalatedReturn()
            $expected = $escalationDecision ? ReturnRequest::STATUS_ESCALATED : ReturnRequest::STATUS_PENDING;
            if ($returnRequest->status !== $expected) {
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

            // The seller's own reason stays on record when an admin overrules it
            $returnRequest->update($escalationDecision
                ? ['status' => $status, 'admin_arbitration_note' => $note, 'escalation_decided_at' => now(), 'resolved_at' => now()]
                : ['status' => $status, 'seller_note' => $note, 'resolved_at' => now()]);

            // Replacement: the sale stands and the seller sends the items again in a ৳0 follow-up parcel, which takes
            // the units from stock; the original's payment waits until it is delivered (WA-ORD-03)
            if ($status === ReturnRequest::STATUS_APPROVED && $returnRequest->requested_action !== 'refund') {
                app(ReplacementParcelService::class)->createFor($returnRequest, $note);

                if ($subOrder->order) {
                    $this->statusReconciler->reconcile($subOrder->order);
                }

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

                    // Offers before stock rows, as checkout locks them (review R-5)
                    app(FlashSaleAllocationService::class)->release($returnRequest->subOrder->items);
                    if (! empty($stockItems)) {
                        StockMovementContext::run(StockMovement::REASON_RETURNED, $returnRequest, fn () => $this->stockReservationService->releaseStock($stockItems));
                    }

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

    /**
     * Roadmap 3.4: the buyer asks B2Bhaat to review a return the seller rejected. Allowed once, within the window
     * after the rejection. The parcel's escrow stays on hold until an admin decides, and every admin is told.
     *
     * @throws Exception
     */
    public function escalateReturnRequest(ReturnRequest $returnRequest, User $customer, string $note): ReturnRequest
    {
        if ($returnRequest->customer_id !== $customer->id) {
            throw new Exception('You can only ask about returns on your own orders.');
        }

        $note = trim($note);
        if (mb_strlen($note) < 10) {
            throw new Exception('Tell us in a few words why the seller\'s decision is wrong (at least 10 characters).');
        }

        $escalated = DB::transaction(function () use ($returnRequest, $note): ReturnRequest {
            $locked = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeEscalated()) {
                throw new Exception($locked->escalated_at !== null
                    ? 'B2Bhaat has already been asked to review this return.'
                    : 'This return can no longer be sent to B2Bhaat for review.');
            }

            // The seller's money may already have been released if the escrow ran in between; then there is nothing left to hold
            if ($this->escrowLedgerService->isReleased($locked->subOrder)) {
                throw new Exception('The seller has already been paid for this parcel, so B2Bhaat can\'t review it online. Please contact support.');
            }

            $locked->update([
                'status' => ReturnRequest::STATUS_ESCALATED,
                'escalated_at' => now(),
                'escalation_note' => $note,
            ]);

            OrderTrackingLog::create([
                'sub_order_id' => $locked->sub_order_id,
                'fulfillment_status_id' => $locked->subOrder->fulfillment_status_id,
                'title' => 'Buyer asked B2Bhaat to review the return',
                'description' => 'The seller rejected the return and the buyer asked B2Bhaat to decide. The seller\'s payment is on hold until then.',
                'hub_location' => 'B2Bhaat support',
                'logged_at' => now(),
            ]);

            return $locked;
        });

        $admins = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['super_admin', 'admin']))
            ->where('is_active', true)
            ->get();
        Notification::send($admins, new ReturnEscalated($escalated->loadMissing('subOrder')));

        return $escalated;
    }

    /**
     * Roadmap 3.4: an admin decides an escalated return. Approving runs the same steps as a seller approval (refund
     * or replacement, stock back, escrow reversed); keeping the rejection closes it and lets the escrow release.
     */
    public function decideEscalatedReturn(ReturnRequest $returnRequest, bool $approve, string $note): void
    {
        if ($approve) {
            $this->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_APPROVED, $note, escalationDecision: true);

            return;
        }

        DB::transaction(function () use ($returnRequest, $note): void {
            $locked = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEscalated()) {
                return;
            }

            $locked->update([
                'status' => ReturnRequest::STATUS_REJECTED,
                'admin_arbitration_note' => $note,
                'escalation_decided_at' => now(),
            ]);

            OrderTrackingLog::create([
                'sub_order_id' => $locked->sub_order_id,
                'fulfillment_status_id' => $locked->subOrder->fulfillment_status_id,
                'title' => 'B2Bhaat kept the seller\'s decision',
                'description' => "The return stays rejected: {$note}",
                'hub_location' => 'B2Bhaat support',
                'logged_at' => now(),
            ]);
        });
    }
}
