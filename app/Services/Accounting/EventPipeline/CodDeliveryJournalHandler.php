<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\SubOrder;
use App\Services\Accounting\JournalPostingService;

class CodDeliveryJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    public function recordCourierCodDelivery(SubOrder $subOrder): AccountingJournalEntry
    {
        $lines = [];
        $order = $subOrder->order;

        $paidAmount = round((float) $subOrder->paidAmount(), 2);
        if ($paidAmount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'courier_cod_receivable',
                debit: $paidAmount,
                credit: 0.00,
                customerId: $order?->customer_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Courier COD receivable for Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        $allocatedDiscount = round((float) $subOrder->allocatedDiscount(), 2);
        if ($allocatedDiscount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'promotional_discount_expense',
                debit: $allocatedDiscount,
                credit: 0.00,
                customerId: $order?->customer_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Allocated promotional coupon discount for Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        $netEarnings = round((float) $subOrder->net_earnings, 2);
        if ($netEarnings > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'seller_escrow_payable',
                debit: 0.00,
                credit: $netEarnings,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Seller net escrow for delivered COD Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        $commission = round((float) $subOrder->commission_amount, 2);
        if ($commission > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'commission_revenue',
                debit: 0.00,
                credit: $commission,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Commission revenue for delivered COD Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        $shipping = round((float) $subOrder->shipping_amount, 2);
        if ($shipping > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'courier_delivery_expense',
                debit: 0.00,
                credit: $shipping,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Courier shipping fee allocated for Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::COD_DELIVERED->value,
            idempotencyKey: "suborder_cod_delivered_{$subOrder->id}",
            description: "Courier COD delivery recorded for Sub-Order #{$subOrder->sub_order_number}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }

    public function recordSelfDeliveredCod(SubOrder $subOrder): ?AccountingJournalEntry
    {
        $order = $subOrder->order;
        $commission = round((float) $subOrder->commission_amount, 2);

        if ($commission <= 0.0) {
            return null;
        }

        $lines = [
            new JournalLineData(
                accountSlug: 'seller_wallet_payable',
                debit: $commission,
                credit: 0.00,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Platform commission charged on self-collected COD for Sub-Order #{$subOrder->sub_order_number}",
            ),
            new JournalLineData(
                accountSlug: 'commission_revenue',
                debit: 0.00,
                credit: $commission,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $order?->id,
                narration: "Platform commission earned on self-collected COD for Sub-Order #{$subOrder->sub_order_number}",
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::COD_SELF_DELIVERED->value,
            idempotencyKey: "suborder_self_cod_delivered_{$subOrder->id}",
            description: "Seller self-collected COD delivery recorded for Sub-Order #{$subOrder->sub_order_number}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }
}
