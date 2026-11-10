<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Refund;
use App\Models\SubOrder;

/**
 * Records money owed back to a customer for one consignment of a paid order (PRA-027). Shared by buyer
 * cancellation, approved refund returns and courier returns; at most one whole-parcel refund per sub-order.
 * Returns of some items record their own refunds (PAY-4, PartialReturnService).
 */
class RefundRecorder
{
    public function recordIfPaid(SubOrder $subOrder, string $reason): ?Refund
    {
        $order = $subOrder->order()->with('paymentStatus')->first();
        if ($order?->paymentStatus?->slug !== 'paid') {
            return null;
        }

        return Refund::firstOrCreate(
            ['sub_order_id' => $subOrder->id, 'return_request_id' => null],
            [
                'order_id' => $order->id,
                'amount' => $subOrder->paidAmount(),
                'reason' => mb_substr($reason, 0, 255),
                'status' => Refund::STATUS_PENDING,
            ],
        );
    }
}
