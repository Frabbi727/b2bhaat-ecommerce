<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentStatus;

/**
 * ORD-1: an order's status is worked out from its parcels (sub-orders), never set by hand.
 *
 * - Every parcel cancelled or returned → Cancelled (a paid order becomes Refunded).
 * - Every parcel finished and at least one delivered → Completed.
 * - Any parcel still waiting or on its way → the order stays open; an order wrongly marked Cancelled or Completed
 *   goes back to Processing.
 */
class OrderStatusReconciler
{
    /** Parcel states that end without the goods staying with the buyer. */
    public const UNDELIVERED_END_STATES = ['cancelled', 'returned'];

    /** Order states that are only correct once every parcel has finished. */
    public const CLOSED_ORDER_STATES = ['cancelled', 'completed'];

    public function reconcile(Order $order): void
    {
        // Two parcels finishing at the same moment must not both decide from a stale view of the order.
        $order = Order::whereKey($order->getKey())->lockForUpdate()->with(['orderStatus', 'paymentStatus'])->first();

        if (! $order) {
            return;
        }

        $parcelStates = $order->subOrders()->with('fulfillmentStatus')->get()
            ->map(fn ($subOrder): ?string => $subOrder->fulfillmentStatus?->slug);

        if ($parcelStates->isEmpty()) {
            return;
        }

        $stillOpen = $parcelStates->contains(
            fn (?string $state): bool => $state !== 'delivered' && ! in_array($state, self::UNDELIVERED_END_STATES, true)
        );
        $currentState = $order->orderStatus?->slug;

        if ($stillOpen) {
            if (in_array($currentState, self::CLOSED_ORDER_STATES, true)) {
                $this->setStatus($order, 'processing');
            }

            return;
        }

        if ($parcelStates->contains('delivered')) {
            $this->setStatus($order, 'completed');

            return;
        }

        $this->setStatus($order, 'cancelled');

        if ($order->paymentStatus?->slug === 'paid') {
            $refunded = PaymentStatus::where('slug', 'refunded')->value('id');
            if ($refunded) {
                $order->update(['payment_status_id' => $refunded]);
            }
        }
    }

    private function setStatus(Order $order, string $slug): void
    {
        $statusId = OrderStatus::where('slug', $slug)->value('id');

        if ($statusId && $order->order_status_id !== $statusId) {
            $order->update(['order_status_id' => $statusId]);
        }
    }
}
