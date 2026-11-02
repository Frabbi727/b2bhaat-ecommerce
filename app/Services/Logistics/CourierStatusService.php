<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Models\FulfillmentStatus;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CourierStatusService
{
    public function __construct(
        protected EscrowLedgerService $escrowLedgerService,
        protected StockReservationService $stockReservationService
    ) {}

    /**
     * Update courier status for a sub-order, record tracking log, and trigger settlement if delivered.
     */
    public function updateStatus(
        SubOrder $subOrder,
        string $statusSlug,
        ?string $hubLocation = null,
        ?string $title = null,
        ?string $description = null,
        ?Carbon $timestamp = null
    ): void {
        DB::transaction(function () use ($subOrder, $statusSlug, $hubLocation, $title, $description, $timestamp) {
            $fulfillmentStatus = FulfillmentStatus::where('slug', $statusSlug)->firstOrFail();

            $subOrder->update([
                'fulfillment_status_id' => $fulfillmentStatus->id,
            ]);

            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $fulfillmentStatus->id,
                'title' => $title ?? ('Courier Update: '.$fulfillmentStatus->name),
                'description' => $description ?? "Package marked as {$fulfillmentStatus->name} by ".($subOrder->courier_name ?? 'Courier Service').'.',
                'hub_location' => $hubLocation ?? 'Transit Hub',
                'logged_at' => $timestamp ?? now(),
            ]);

            // Lifecycle event: Delivered
            if ($statusSlug === 'delivered') {
                $this->handleDeliveredSettlement($subOrder);
            }

            // Lifecycle event: Returned (RTO / Refusal)
            if ($statusSlug === 'returned') {
                $this->handleReturnedConsignment($subOrder);
            }
        });
    }

    protected function handleReturnedConsignment(SubOrder $subOrder): void
    {
        // 1. Release reserved stock back to variation catalog
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

        // 2. Refund escrow if held
        $this->escrowLedgerService->refundCancelledEscrow($subOrder, 'Returned to origin (RTO) by courier service');

        // 3. Reconcile parent order if all sub-orders are returned/cancelled
        $parentOrder = $subOrder->order;
        if ($parentOrder) {
            $terminalSlugs = ['returned', 'cancelled'];
            $terminalIds = FulfillmentStatus::whereIn('slug', $terminalSlugs)->pluck('id');

            $allTerminal = $parentOrder->subOrders()
                ->whereNotIn('fulfillment_status_id', $terminalIds)
                ->doesntExist();

            if ($allTerminal) {
                $cancelledStatus = OrderStatus::where('slug', 'cancelled')->first();
                if ($cancelledStatus) {
                    $parentOrder->update(['order_status_id' => $cancelledStatus->id]);
                }

                $refundedPaymentStatus = PaymentStatus::where('slug', 'refunded')->first();
                if ($refundedPaymentStatus && $parentOrder->paymentStatus?->slug === 'paid') {
                    $parentOrder->update(['payment_status_id' => $refundedPaymentStatus->id]);
                }
            }
        }
    }

    protected function handleDeliveredSettlement(SubOrder $subOrder): void
    {
        // 1. Release or credit seller wallet
        $this->escrowLedgerService->creditDeliveredSubOrder($subOrder);

        // 2. Reconcile parent order
        $parentOrder = $subOrder->order;
        if ($parentOrder) {
            // If COD / unpaid, mark parent order as paid
            $paidStatus = PaymentStatus::where('slug', 'paid')->first();
            if ($paidStatus && $parentOrder->payment_status_id !== $paidStatus->id) {
                $parentOrder->update(['payment_status_id' => $paidStatus->id]);
            }

            // Check if ALL sibling sub-orders are delivered
            $deliveredStatusId = FulfillmentStatus::where('slug', 'delivered')->value('id');
            $allDelivered = $parentOrder->subOrders()
                ->where('fulfillment_status_id', '!=', $deliveredStatusId)
                ->doesntExist();

            if ($allDelivered) {
                $completedOrderStatus = OrderStatus::where('slug', 'completed')->first();
                if ($completedOrderStatus) {
                    $parentOrder->update(['order_status_id' => $completedOrderStatus->id]);
                }
            }
        }
    }
}
