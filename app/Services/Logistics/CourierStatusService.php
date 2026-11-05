<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\InvalidStatusTransitionException;
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
    /**
     * Legal fulfilment moves. A courier may skip intermediate scans, so later states are reachable directly.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'unfulfilled' => ['processing', 'shipped', 'in_transit', 'cancelled'],
        'processing' => ['shipped', 'in_transit', 'out_for_delivery', 'delivered', 'returned', 'cancelled'],
        'shipped' => ['in_transit', 'out_for_delivery', 'delivered', 'returned'],
        'in_transit' => ['out_for_delivery', 'delivered', 'returned'],
        'out_for_delivery' => ['in_transit', 'delivered', 'returned'],
        'delivered' => ['returned'],
        'returned' => [],
        'cancelled' => [],
    ];

    public static function canTransition(?string $from, string $to): bool
    {
        if ($from === null) {
            return true;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    public function __construct(
        protected EscrowLedgerService $escrowLedgerService,
        protected StockReservationService $stockReservationService
    ) {}

    /**
     * Update courier status for a sub-order, record tracking log, and trigger settlement if delivered.
     * Repeating the current status is a no-op; an illegal move throws.
     *
     * @throws InvalidStatusTransitionException
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
            $subOrder = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->with('fulfillmentStatus')->firstOrFail();
            $currentSlug = $subOrder->fulfillmentStatus?->slug;

            if ($currentSlug === $statusSlug) {
                return;
            }

            if (! self::canTransition($currentSlug, $statusSlug)) {
                throw InvalidStatusTransitionException::between($currentSlug, $statusSlug);
            }

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

        // 2. Undo the seller side: refund the hold, or reverse an already released credit
        $this->escrowLedgerService->reverseReturnedSubOrder($subOrder, 'Returned to origin (RTO) by courier service');

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
        // 1. Hold the seller's share (prepaid: already held, COD: held now per D2).
        //    Only the scheduled escrow:release-delivered command releases it, after the holding period.
        $this->escrowLedgerService->holdInEscrow($subOrder);

        // 2. Reconcile parent order
        $parentOrder = $subOrder->order;
        if ($parentOrder) {
            // COD: the courier collected the cash at the door, so the order is now paid.
            // Prepaid orders are marked paid only by payment verification, never by delivery (PRA-006).
            $paidStatus = PaymentStatus::where('slug', 'paid')->first();
            if ($paidStatus && $parentOrder->paymentMethod?->slug === 'cod' && $parentOrder->payment_status_id !== $paidStatus->id) {
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
