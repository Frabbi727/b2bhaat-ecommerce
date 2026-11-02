<?php

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Settings\StoreConfigurationService;
use Exception;
use Illuminate\Support\Facades\DB;

class OrderDisputeService
{
    public function __construct(
        protected StockReservationService $stockReservationService,
        protected EscrowLedgerService $escrowLedgerService
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
            $cancelledFulfillment = FulfillmentStatus::where('slug', 'cancelled')->firstOrFail();

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

            // 5. Reconcile parent order: if all sub-orders are cancelled, mark parent order cancelled & refunded
            $parentOrder = $subOrder->order;
            if ($parentOrder) {
                $allCancelled = $parentOrder->subOrders()
                    ->where('fulfillment_status_id', '!=', $cancelledFulfillment->id)
                    ->doesntExist();

                if ($allCancelled) {
                    $cancelledOrderStatus = OrderStatus::where('slug', 'cancelled')->first();
                    if ($cancelledOrderStatus) {
                        $parentOrder->update(['order_status_id' => $cancelledOrderStatus->id]);
                    }

                    $refundedPaymentStatus = PaymentStatus::where('slug', 'refunded')->first();
                    if ($refundedPaymentStatus && $parentOrder->paymentStatus?->slug === 'paid') {
                        $parentOrder->update(['payment_status_id' => $refundedPaymentStatus->id]);
                    }
                }
            }
        });
    }

    /**
     * Cancel complete parent order.
     *
     * @throws Exception
     */
    public function cancelFullOrder(Order $order, string $reason = 'Cancelled by buyer'): void
    {
        DB::transaction(function () use ($order, $reason) {
            foreach ($order->subOrders as $subOrder) {
                if (in_array($subOrder->fulfillmentStatus?->slug, ['unfulfilled', 'processing'])) {
                    $this->cancelSubOrder($subOrder, $reason);
                }
            }

            $cancelledOrderStatus = OrderStatus::where('slug', 'cancelled')->first();
            if ($cancelledOrderStatus) {
                $order->update(['order_status_id' => $cancelledOrderStatus->id]);
            }
        });
    }

    /**
     * Submit return / dispute request for a delivered sub-order.
     *
     * @param array{
     *     reason: string,
     *     requested_action: string,
     *     description: string,
     *     image_proof_url?: ?string
     * } $data
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

        return DB::transaction(function () use ($subOrder, $customer, $data) {
            $returnRequest = ReturnRequest::create([
                'sub_order_id' => $subOrder->id,
                'customer_id' => $customer->id,
                'reason' => $data['reason'],
                'requested_action' => $data['requested_action'] ?? 'refund',
                'description' => $data['description'],
                'image_proof_url' => $data['image_proof_url'] ?? null,
                'status' => ReturnRequest::STATUS_PENDING,
            ]);

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
            $returnRequest->update([
                'status' => $status,
                'seller_note' => $note,
                'resolved_at' => now(),
            ]);

            if ($status === ReturnRequest::STATUS_APPROVED) {
                $returnedStatus = FulfillmentStatus::where('slug', 'returned')->first();
                if ($returnedStatus) {
                    $returnRequest->subOrder->update([
                        'fulfillment_status_id' => $returnedStatus->id,
                    ]);

                    OrderTrackingLog::create([
                        'sub_order_id' => $returnRequest->sub_order_id,
                        'fulfillment_status_id' => $returnedStatus->id,
                        'title' => 'Return Approved by Supplier',
                        'description' => "Return approved: {$note}. Replacement or refund initiated.",
                        'hub_location' => 'Merchant Hub',
                        'logged_at' => now(),
                    ]);
                }
            }
        });
    }
}
