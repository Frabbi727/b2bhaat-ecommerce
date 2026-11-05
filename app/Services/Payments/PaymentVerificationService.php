<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Sms\OrderNotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marks a manually paid (bKash/Nagad) order as paid once an admin has checked the TrxID, and holds seller escrow.
 */
class PaymentVerificationService
{
    public function __construct(
        protected EscrowLedgerService $escrowLedgerService,
        protected StockReservationService $stockReservationService
    ) {}

    /**
     * The TrxID could not be matched: fail the payment, cancel every consignment that has not shipped and put its
     * reserved stock back (final review I4). Nothing was held in escrow, so no ledger change.
     */
    public function rejectPayment(Order $order, string $reason): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to reject a payment.');
        }

        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->with('paymentStatus')->firstOrFail();

            if ($order->paymentStatus?->slug !== 'awaiting_verification') {
                throw new DomainException("Order #{$order->order_number} is not awaiting payment verification.");
            }

            $cancelled = FulfillmentStatus::where('slug', 'cancelled')->firstOrFail();

            foreach ($order->subOrders()->with('fulfillmentStatus', 'items')->lockForUpdate()->get() as $subOrder) {
                if (! CourierStatusService::canTransition($subOrder->fulfillmentStatus?->slug, 'cancelled')) {
                    continue;
                }

                $stockItems = $subOrder->items
                    ->filter(fn ($item) => $item->variation_id)
                    ->map(fn ($item) => ['variation_id' => $item->variation_id, 'quantity' => $item->quantity])
                    ->values()
                    ->all();
                if ($stockItems !== []) {
                    $this->stockReservationService->releaseStock($stockItems);
                }

                $subOrder->update(['fulfillment_status_id' => $cancelled->id]);

                OrderTrackingLog::create([
                    'sub_order_id' => $subOrder->id,
                    'fulfillment_status_id' => $cancelled->id,
                    'title' => 'Payment Rejected',
                    'description' => "Payment could not be verified: {$reason}. Order cancelled and stock restored.",
                    'hub_location' => 'Payments Desk',
                    'logged_at' => now(),
                ]);
            }

            $order->update([
                'payment_status_id' => PaymentStatus::where('slug', 'failed')->firstOrFail()->id,
                'order_status_id' => OrderStatus::where('slug', 'cancelled')->firstOrFail()->id,
            ]);

            return $order->fresh();
        });
    }

    public function markVerified(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->with('paymentStatus')->firstOrFail();

            if ($order->paymentStatus?->slug !== 'awaiting_verification') {
                throw new DomainException("Order #{$order->order_number} is not awaiting payment verification.");
            }

            $order->update(['payment_status_id' => PaymentStatus::where('slug', 'paid')->firstOrFail()->id]);

            foreach ($order->subOrders as $subOrder) {
                $this->escrowLedgerService->holdInEscrow($subOrder);
            }

            DB::afterCommit(function () use ($order): void {
                try {
                    app(OrderNotificationService::class)->notifySellersOfNewOrder($order);
                } catch (\Throwable $exception) {
                    Log::warning('Seller new-order SMS not sent: '.$exception->getMessage(), ['order_id' => $order->id]);
                }
            });

            return $order->fresh();
        });
    }
}
