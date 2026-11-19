<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Events\Finance\RefundSettledEvent;
use App\Models\Order;
use App\Models\PaymentStatus;
use App\Models\Refund;
use App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler;
use App\Services\Accounting\SettlementService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AR-05 / AR-15: paying a buyer back. One transfer reference settles one refund per method, the books record the
 * payment, and the order's payment status follows the money actually sent:
 *
 * - "Refund pending" while any refund is still to be sent (never "Paid" for an order that owes money back, M-3)
 * - "Refunded" once every refund is sent and nothing of the order was kept
 * - "Partially refunded" once every refund is sent but part of the order was delivered and kept
 */
class RefundService
{
    /** @var array<string, string> */
    public const METHODS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'Bank Transfer', 'cash' => 'Cash'];

    public function __construct(private readonly CustomerRefundDisbursementJournalHandler $journal) {}

    /**
     * Returns false when the refund was already paid (nothing changes).
     *
     * @throws DomainException when the method is unknown, the reference is empty or already used
     */
    public function markPaid(Refund $refund, string $method, string $reference): bool
    {
        $reference = trim($reference);
        $key = SettlementService::referenceKey($reference);

        if (! array_key_exists($method, self::METHODS)) {
            throw new DomainException('Choose how the refund was sent.');
        }
        if ($key === '') {
            throw new DomainException('Enter the transaction or reference ID of the refund.');
        }

        $paid = DB::transaction(function () use ($refund, $method, $reference, $key): bool {
            $locked = Refund::whereKey($refund->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                return false;
            }

            $used = Refund::query()->where('method', $method)->where('reference_key', $key)->whereKeyNot($locked->id)->first();
            if ($used) {
                throw new DomainException("Reference {$reference} was already used for refund #{$used->id} by ".self::METHODS[$method].'. Check the transfer and enter its own reference.');
            }

            $locked->update([
                'status' => Refund::STATUS_PAID,
                'method' => $method,
                'reference' => $reference,
                'reference_key' => $key,
                'paid_at' => now(),
            ]);

            $this->journal->recordRefundDisbursed($locked);

            if ($locked->order) {
                $this->syncPaymentStatus($locked->order);
            }

            return true;
        });

        if ($paid) {
            DB::afterCommit(function () use ($refund): void {
                try {
                    event(new RefundSettledEvent($refund->fresh()));
                } catch (\Throwable $e) {
                    Log::warning('Refund settled event failed: '.$e->getMessage(), ['refund_id' => $refund->id]);
                }
            });
        }

        return $paid;
    }

    /**
     * Set a paid order's payment status from its refunds. Orders that were never paid are left alone.
     */
    public function syncPaymentStatus(Order $order): void
    {
        $order = Order::whereKey($order->getKey())->lockForUpdate()->with(['paymentStatus', 'orderStatus'])->first();
        if (! $order || ! in_array($order->paymentStatus?->slug, PaymentStatus::MONEY_RECEIVED, true)) {
            return;
        }

        $refunds = Refund::where('order_id', $order->id)->get(['status']);
        $pendingCount = $refunds->where('status', Refund::STATUS_PENDING)->count();
        $paidCount = $refunds->where('status', Refund::STATUS_PAID)->count();

        $slug = match (true) {
            $pendingCount > 0 => 'refund_pending',
            $paidCount === 0 => 'paid',
            $order->orderStatus?->slug === 'cancelled' => 'refunded',
            default => 'partially_refunded',
        };

        $statusId = PaymentStatus::where('slug', $slug)->value('id');
        if ($statusId && $order->payment_status_id !== $statusId) {
            $order->update(['payment_status_id' => $statusId]);
        }
    }
}
