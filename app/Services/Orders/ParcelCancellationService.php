<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Promotions\FlashSaleAllocationService;
use App\Services\Sms\OrderNotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AR-12 / WA-ORD-01: the one way a parcel gets cancelled, whoever cancels it. It puts the flash-sale units and stock
 * back, marks the parcel cancelled with who and why, logs it for the buyer's tracking page, records the refund owed
 * for a paid parcel, gives back any escrow hold and brings the order's status up to date.
 *
 * Who may cancel what is decided here too: the buyer before the seller has packed (OrderDisputeService adds the time
 * window), the seller and an admin until the parcel leaves the seller. After that the parcel comes back as a return.
 */
class ParcelCancellationService
{
    public const BY_BUYER = 'buyer';

    public const BY_SELLER = 'seller';

    public const BY_ADMIN = 'admin';

    /** Payment rejected or not confirmed in time, or the checkout reservation expired */
    public const BY_SYSTEM = 'system';

    /** States the seller still holds the goods in: seller and admin can cancel */
    public const NOT_YET_DISPATCHED = ['unfulfilled', 'processing'];

    /** @var array<string, string> reason code => what the buyer and the admin see */
    public const DECLINE_REASONS = [
        'out_of_stock' => 'Out of stock',
        'cannot_deliver_area' => "Can't deliver to this area",
        'price_error' => 'Price was listed wrong',
        'buyer_unreachable' => "Couldn't reach the buyer",
        'other' => 'Other reason',
    ];

    public function __construct(
        private readonly StockReservationService $stockReservations,
        private readonly FlashSaleAllocationService $flashSales,
        private readonly EscrowLedgerService $escrowLedger,
        private readonly RefundRecorder $refundRecorder,
        private readonly OrderStatusReconciler $statusReconciler,
    ) {}

    /**
     * Cancel one parcel. Returns false when it was already cancelled (nothing is released twice).
     *
     * @param  string  $cancelledBy  one of the BY_* constants
     * @param  list<string>|null  $onlyFrom  the states this caller may cancel from, checked under the parcel lock so a
     *                                       parcel packed or shipped at the same moment is not cancelled; null = any
     *                                       state that can move to cancelled
     *
     * @throws DomainException when the parcel has moved past a state that can be cancelled
     */
    public function cancel(
        SubOrder $subOrder,
        string $cancelledBy,
        string $reason,
        ?User $cancelledByUser = null,
        ?string $trackingTitle = null,
        ?string $trackingDescription = null,
        string $hubLocation = 'Merchant Hub',
        ?array $onlyFrom = null,
    ): bool {
        return DB::transaction(function () use ($subOrder, $cancelledBy, $reason, $cancelledByUser, $trackingTitle, $trackingDescription, $hubLocation, $onlyFrom): bool {
            // Order before parcel, as everywhere else, so two cancellations can't deadlock (Wave 1 review M-1)
            Order::whereKey($subOrder->parent_order_id)->lockForUpdate()->first();
            $subOrder = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->with('fulfillmentStatus', 'items')->firstOrFail();
            $currentSlug = $subOrder->fulfillmentStatus?->slug;

            if ($currentSlug === 'cancelled') {
                return false;
            }
            if (! CourierStatusService::canTransition($currentSlug, 'cancelled') || ($onlyFrom !== null && ! in_array($currentSlug, $onlyFrom, true))) {
                throw new DomainException("Consignment #{$subOrder->sub_order_number} is {$currentSlug} and can no longer be cancelled.");
            }

            $cancelled = FulfillmentStatus::where('slug', 'cancelled')->firstOrFail();

            // A paid parcel: record what the buyer is owed (PRA-027)
            $this->refundRecorder->recordIfPaid($subOrder, "Cancelled: {$reason}");

            // Offers before stock rows, the order checkout locks them in, so the two can't deadlock (review R-5)
            $this->flashSales->release($subOrder->items);
            $stockItems = $subOrder->items
                ->filter(fn ($item) => $item->variation_id)
                ->map(fn ($item) => ['variation_id' => $item->variation_id, 'quantity' => $item->quantity])
                ->values()
                ->all();
            if ($stockItems !== []) {
                $this->stockReservations->releaseStock($stockItems);
            }

            $subOrder->update([
                'fulfillment_status_id' => $cancelled->id,
                'cancelled_by' => $cancelledBy,
                'cancelled_by_user_id' => $cancelledByUser?->id,
                'cancel_reason' => mb_substr($reason, 0, 255),
                'cancelled_at' => now(),
            ]);

            // A held escrow amount goes back (nothing is held for an unpaid parcel)
            $this->escrowLedger->refundCancelledEscrow($subOrder, $reason);

            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $cancelled->id,
                'title' => $trackingTitle ?? 'Consignment Cancelled',
                'description' => $trackingDescription ?? "Order cancelled: {$reason}. Reserved stock restored to catalog inventory.",
                'hub_location' => $hubLocation,
                'logged_at' => now(),
            ]);

            // The order's status follows its parcels (ORD-1)
            if ($subOrder->order) {
                $this->statusReconciler->reconcile($subOrder->order);
            }

            return true;
        });
    }

    /**
     * WA-ORD-01: the seller can't fill the parcel. Allowed until it leaves the seller; the buyer is told why by SMS.
     *
     * @throws DomainException
     */
    public function declineBySeller(SubOrder $subOrder, User $seller, string $reason): void
    {
        if ($subOrder->seller_id !== $seller->id) {
            throw new DomainException('You can only decline your own parcels.');
        }
        $this->assertNotYetDispatched($subOrder, 'declined');

        $declined = $this->cancel(
            subOrder: $subOrder,
            cancelledBy: self::BY_SELLER,
            reason: $reason,
            cancelledByUser: $seller,
            trackingTitle: 'Declined by Seller',
            trackingDescription: "The seller could not fulfil this parcel: {$reason}. Reserved stock restored to catalog inventory.",
            onlyFrom: self::NOT_YET_DISPATCHED,
        );

        if ($declined) {
            $this->tellBuyerAfterCommit($subOrder, "your parcel from {$this->storeName($subOrder)} was cancelled by the seller ({$reason})");
        }
    }

    /**
     * WA-ORD-01: support cancels a parcel, e.g. a seller who never answers. Once the parcel has left the seller it has
     * to come back first, which the courier update "Returned to seller" records.
     *
     * @throws DomainException
     */
    public function cancelByAdmin(SubOrder $subOrder, User $admin, string $reason): void
    {
        $this->assertNotYetDispatched($subOrder, 'cancelled', 'Record it as "Returned to seller" on the Courier Updates page once it is back.');

        $cancelled = $this->cancel(
            subOrder: $subOrder,
            cancelledBy: self::BY_ADMIN,
            reason: $reason,
            cancelledByUser: $admin,
            trackingTitle: 'Cancelled by B2Bhaat',
            trackingDescription: "Cancelled by B2Bhaat support: {$reason}. Reserved stock restored to catalog inventory.",
            hubLocation: 'B2Bhaat Support',
            onlyFrom: self::NOT_YET_DISPATCHED,
        );

        if ($cancelled) {
            $this->tellBuyerAfterCommit($subOrder, "your parcel from {$this->storeName($subOrder)} was cancelled by B2Bhaat ({$reason})");
        }
    }

    /**
     * AR-16: the seller has packed the parcel, so the buyer can no longer cancel it online.
     *
     * @throws DomainException
     */
    public function markPacked(SubOrder $subOrder, User $seller): void
    {
        if ($subOrder->seller_id !== $seller->id) {
            throw new DomainException('You can only update your own parcels.');
        }

        app(CourierStatusService::class)->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'processing',
            hubLocation: 'Merchant Hub',
            title: 'Packed',
            description: 'The seller has packed your parcel and is getting it ready to ship.',
        );
    }

    /**
     * @throws DomainException
     */
    private function assertNotYetDispatched(SubOrder $subOrder, string $verb, string $hint = ''): void
    {
        $slug = $subOrder->fresh('fulfillmentStatus')?->fulfillmentStatus?->slug;

        if ($slug === 'cancelled') {
            return;
        }
        if (! in_array($slug, self::NOT_YET_DISPATCHED, true)) {
            throw new DomainException(trim("Consignment #{$subOrder->sub_order_number} has already left the seller ({$slug}) and can't be {$verb}. {$hint}"));
        }
    }

    private function storeName(SubOrder $subOrder): string
    {
        return $subOrder->seller?->sellerProfile?->store_name ?? 'the seller';
    }

    private function tellBuyerAfterCommit(SubOrder $subOrder, string $status): void
    {
        DB::afterCommit(function () use ($subOrder, $status): void {
            try {
                $order = $subOrder->order()->first();
                if ($order) {
                    app(OrderNotificationService::class)->sendOrderStatusUpdate($order, $status);
                }
            } catch (\Throwable $exception) {
                Log::warning('Parcel cancellation SMS not sent: '.$exception->getMessage(), ['sub_order_id' => $subOrder->id]);
            }
        });
    }
}
