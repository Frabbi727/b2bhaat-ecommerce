<?php

namespace App\Services\Logistics;

use App\Models\FulfillmentStatus;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Services\Escrow\EscrowLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CourierStatusService
{
    public function __construct(
        protected EscrowLedgerService $escrowLedgerService
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
        });
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
