<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Services\Accounting\EventPipeline\CodDeliveryJournalHandler;
use App\Services\Accounting\EventPipeline\CommissionRecognitionJournalHandler;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderStatusReconciler;
use App\Services\Orders\RefundRecorder;
use App\Services\Promotions\FlashSaleAllocationService;
use Carbon\Carbon;
use DomainException;
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
        protected StockReservationService $stockReservationService,
        protected RefundRecorder $refundRecorder,
        protected CodDeliveryJournalHandler $codDeliveryJournalHandler,
        protected CommissionRecognitionJournalHandler $commissionRecognitionJournalHandler,
        protected OrderStatusReconciler $statusReconciler,
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
            // Order before parcel, as everywhere else (Wave 1 review M-1).
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();

            $subOrder = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->with(['fulfillmentStatus', 'order.paymentMethod'])->firstOrFail();
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

    /**
     * ORD-2: the seller hands a parcel over (courier dispatch, pickup booking or self-delivery). Same lock and status
     * rules as courier updates, and only once the order's payment is cleared. The parcel's courier details are saved
     * with the new status. Handing over again in the same status updates the details (for example a rebooking).
     *
     * @param  array<string, mixed>  $parcelDetails  courier_name, tracking_number, is_self_delivered, handover_code
     *
     * @throws InvalidStatusTransitionException when the parcel can't move to that status
     * @throws DomainException when the order's payment isn't cleared
     */
    public function handOver(SubOrder $subOrder, string $statusSlug, array $parcelDetails, string $title, string $description, string $hubLocation): SubOrder
    {
        DB::transaction(function () use ($subOrder, $statusSlug, $parcelDetails, $title, $description, $hubLocation): void {
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();

            $subOrder = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->with(['fulfillmentStatus', 'order.paymentMethod', 'order.paymentStatus'])->firstOrFail();
            $currentSlug = $subOrder->fulfillmentStatus?->slug;

            if ($currentSlug !== $statusSlug && ! self::canTransition($currentSlug, $statusSlug)) {
                throw InvalidStatusTransitionException::between($currentSlug, $statusSlug);
            }

            if (! $subOrder->isClearedForDispatch()) {
                throw new DomainException("Sub-Order #{$subOrder->sub_order_number} can't be sent yet: the buyer's payment hasn't been confirmed.");
            }

            $status = FulfillmentStatus::where('slug', $statusSlug)->firstOrFail();
            $subOrder->forceFill($parcelDetails + ['fulfillment_status_id' => $status->id])->save();

            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $status->id,
                'title' => $title,
                'description' => $description,
                'hub_location' => $hubLocation,
                'logged_at' => now(),
            ]);
        });

        // The caller's copy shows the saved state
        return $subOrder->refresh();
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
        app(FlashSaleAllocationService::class)->release($subOrder->items);

        // 2. Undo the seller side: refund the hold, or reverse an already released credit
        $this->escrowLedgerService->reverseReturnedSubOrder($subOrder, 'Returned to origin (RTO) by courier service');

        // 3. A paid buyer is owed this consignment back (final review I3); before the parent flips to "refunded"
        $this->refundRecorder->recordIfPaid($subOrder, 'Returned to origin (RTO) by courier service');

        // 4. The order's status follows its parcels (ORD-1)
        if ($subOrder->order) {
            $this->statusReconciler->reconcile($subOrder->order);
        }
    }

    protected function handleDeliveredSettlement(SubOrder $subOrder): void
    {
        // 1. Courier COD / prepaid: hold the seller's share (prepaid already held, COD held now per D2); only the
        //    scheduled escrow:release-delivered command releases it, after the holding period.
        //    Self-delivered COD: the seller already has the cash, so charge the commission instead (C2).
        $isCod = $subOrder->order?->paymentMethod?->slug === 'cod';
        if ($isCod && $subOrder->is_self_delivered) {
            $this->escrowLedgerService->settleSelfCollectedCod($subOrder);
            $this->codDeliveryJournalHandler->recordSelfDeliveredCod($subOrder);
        } else {
            $this->escrowLedgerService->holdInEscrow($subOrder);
            if ($isCod) {
                $this->codDeliveryJournalHandler->recordCourierCodDelivery($subOrder);
            } else {
                // Prepaid: the commission held since payment is earned now that the parcel is delivered (ACC-11).
                $this->commissionRecognitionJournalHandler->recordCommissionEarned($subOrder);
            }
        }

        // 2. Reconcile parent order
        $parentOrder = $subOrder->order;
        if ($parentOrder) {
            // COD: the courier collected the cash at the door, so the order is now paid.
            // Prepaid orders are marked paid only by payment verification, never by delivery (PRA-006).
            $paidStatus = PaymentStatus::where('slug', 'paid')->first();
            if ($paidStatus && $parentOrder->paymentMethod?->slug === 'cod' && $parentOrder->payment_status_id !== $paidStatus->id) {
                $parentOrder->update(['payment_status_id' => $paidStatus->id]);
            }

            // The order's status follows its parcels (ORD-1)
            $this->statusReconciler->reconcile($parentOrder);
        }
    }
}
