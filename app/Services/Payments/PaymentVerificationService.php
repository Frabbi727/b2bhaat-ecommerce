<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Listeners\Orders\SendSellerNewConsignmentEmailListener;
use App\Models\FlashSaleProduct;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\EventPipeline\RefundJournalHandler;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\RefundRecorder;
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
        protected StockReservationService $stockReservationService,
        protected OrderPaymentJournalHandler $orderPaymentJournalHandler,
        protected RefundRecorder $refundRecorder,
        protected RefundJournalHandler $refundJournalHandler,
    ) {}

    /**
     * The TrxID could not be matched: fail the payment, cancel every consignment that has not shipped and put its
     * reserved stock and flash-sale units back (final review I4, PAY-2). Nothing was held in escrow, so no ledger change.
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

            $this->cancelUnpaidConsignments(
                $order,
                'Payment Rejected',
                "Payment could not be verified: {$reason}. Order cancelled and stock restored.",
            );

            $order->update([
                'payment_status_id' => PaymentStatus::where('slug', 'failed')->firstOrFail()->id,
                'order_status_id' => OrderStatus::where('slug', 'cancelled')->firstOrFail()->id,
            ]);

            return $order->fresh();
        });
    }

    /**
     * PAY-2: nobody checked the TrxID within the time limit, so the order is cancelled as "payment not confirmed"
     * (not "rejected": the buyer may still have paid) and its stock and flash-sale units go back on sale. Returns null
     * when the order was checked in the meantime or is not old enough yet.
     */
    public function expireUnverified(Order $order, int $timeoutHours): ?Order
    {
        $expired = DB::transaction(function () use ($order, $timeoutHours): ?Order {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->with('paymentStatus')->firstOrFail();

            if ($order->paymentStatus?->slug !== 'awaiting_verification' || $order->created_at->gt(now()->subHours($timeoutHours))) {
                return null;
            }

            $this->cancelUnpaidConsignments(
                $order,
                'Payment Not Confirmed',
                "The payment was not confirmed within {$timeoutHours} hours, so the order was cancelled and its stock restored. If you paid, contact support with your TrxID for a refund.",
            );

            $order->update([
                'payment_status_id' => PaymentStatus::where('slug', 'expired')->firstOrFail()->id,
                'order_status_id' => OrderStatus::where('slug', 'cancelled')->firstOrFail()->id,
            ]);

            return $order->fresh();
        });

        if ($expired) {
            DB::afterCommit(function () use ($expired): void {
                try {
                    app(OrderNotificationService::class)->sendPaymentNotConfirmed($expired);
                } catch (\Throwable $exception) {
                    Log::warning('Payment-not-confirmed SMS not sent: '.$exception->getMessage(), ['order_id' => $expired->id]);
                }
            });
        }

        return $expired;
    }

    /**
     * Cancel every consignment of an unpaid order that has not shipped, and put its stock and flash-sale units back.
     */
    private function cancelUnpaidConsignments(Order $order, string $title, string $description): void
    {
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

            // The flash-sale units this order claimed are free for other buyers again.
            foreach ($subOrder->items->filter(fn ($item) => $item->flash_sale_product_id) as $item) {
                $offer = FlashSaleProduct::whereKey($item->flash_sale_product_id)->lockForUpdate()->first();
                if ($offer) {
                    $offer->update(['units_sold' => max(0, (int) $offer->units_sold - (int) $item->quantity)]);
                }
            }

            $subOrder->update(['fulfillment_status_id' => $cancelled->id]);

            OrderTrackingLog::create([
                'sub_order_id' => $subOrder->id,
                'fulfillment_status_id' => $cancelled->id,
                'title' => $title,
                'description' => $description,
                'hub_location' => 'Payments Desk',
                'logged_at' => now(),
            ]);
        }
    }

    public function markVerified(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->with('paymentStatus')->firstOrFail();

            if ($order->paymentStatus?->slug !== 'awaiting_verification') {
                throw new DomainException("Order #{$order->order_number} is not awaiting payment verification.");
            }

            $order->update(['payment_status_id' => PaymentStatus::where('slug', 'paid')->firstOrFail()->id]);

            $this->orderPaymentJournalHandler->recordPrepaidPayment($order);

            // A consignment the buyer cancelled while we were checking the payment: the money arrived, so we owe it
            // back instead of holding it for the seller (PAY-1).
            // Sub-orders are locked so a buyer cancelling at this moment waits for us, then sees the order as paid.
            foreach ($order->subOrders()->with('fulfillmentStatus')->lockForUpdate()->get() as $subOrder) {
                if ($subOrder->fulfillmentStatus?->slug === 'cancelled') {
                    $reason = 'Cancelled before the payment was verified';
                    $this->refundRecorder->recordIfPaid($subOrder, $reason);
                    // Take the cancelled part back out of seller escrow, commission and shipping, and owe it to the buyer.
                    $this->refundJournalHandler->recordPrepaidCancellationRefund($subOrder, 0.0, $reason);

                    continue;
                }

                $this->escrowLedgerService->holdInEscrow($subOrder);
            }

            DB::afterCommit(function () use ($order): void {
                try {
                    app(OrderNotificationService::class)->notifySellersOfNewOrder($order);
                } catch (\Throwable $exception) {
                    Log::warning('Seller new-order SMS not sent: '.$exception->getMessage(), ['order_id' => $order->id]);
                }

                try {
                    app(SendSellerNewConsignmentEmailListener::class)->notifySellers($order);
                } catch (\Throwable $exception) {
                    Log::warning('Seller new-order email not sent: '.$exception->getMessage(), ['order_id' => $order->id]);
                }
            });

            return $order->fresh();
        });
    }
}
