<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentStatus;
use App\Services\Escrow\EscrowLedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Marks a manually paid (bKash/Nagad) order as paid once an admin has checked the TrxID, and holds seller escrow.
 */
class PaymentVerificationService
{
    public function __construct(
        protected EscrowLedgerService $escrowLedgerService
    ) {}

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

            return $order->fresh();
        });
    }
}
