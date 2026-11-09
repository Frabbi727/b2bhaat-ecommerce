<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Order;
use App\Services\Accounting\JournalPostingService;

class OrderPaymentJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    public function recordPrepaidPayment(Order $order): AccountingJournalEntry
    {
        $lines = [];

        // 1. Debit Payment Gateway Clearing for cash received
        $totalAmount = round((float) $order->total_amount, 2);
        if ($totalAmount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'gateway_clearing',
                debit: $totalAmount,
                credit: 0.00,
                customerId: $order->customer_id,
                orderId: $order->id,
                narration: "Prepaid customer payment for Order #{$order->order_number}",
            );
        }

        // 2. Debit Platform Promotional Discount Expense if discount applied
        $discountAmount = round((float) $order->discount_amount, 2);
        if ($discountAmount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'promotional_discount_expense',
                debit: $discountAmount,
                credit: 0.00,
                customerId: $order->customer_id,
                orderId: $order->id,
                narration: "Platform promotional discount for Order #{$order->order_number}",
            );
        }

        // 3. Credit Seller Escrow, Deferred Commission, and Shipping for each sub-order
        $order->loadMissing('subOrders');
        foreach ($order->subOrders as $subOrder) {
            // Net payable to seller held in escrow
            $netEarnings = round((float) $subOrder->net_earnings, 2);
            if ($netEarnings > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'seller_escrow_payable',
                    debit: 0.00,
                    credit: $netEarnings,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $order->id,
                    narration: "Seller net escrow for Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // Platform commission: not earned until the parcel is delivered (ACC-11)
            $commissionAmount = round((float) $subOrder->commission_amount, 2);
            if ($commissionAmount > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: CommissionRecognitionJournalHandler::DEFERRED_ACCOUNT,
                    debit: 0.00,
                    credit: $commissionAmount,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $order->id,
                    narration: "Commission held until delivery for Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // Shipping fee: the platform earns it when its courier delivers; the seller earns it when they deliver (ACC-1)
            $shippingAmount = round((float) $subOrder->shipping_amount, 2);
            if ($shippingAmount > 0.0) {
                if ($subOrder->is_self_delivered) {
                    // Self-delivered shipping goes to seller escrow
                    $lines[] = new JournalLineData(
                        accountSlug: 'seller_escrow_payable',
                        debit: 0.00,
                        credit: $shippingAmount,
                        sellerId: $subOrder->seller_id,
                        subOrderId: $subOrder->id,
                        orderId: $order->id,
                        narration: "Self-delivery shipping fee to seller for Sub-Order #{$subOrder->sub_order_number}",
                    );
                } else {
                    $lines[] = new JournalLineData(
                        accountSlug: 'shipping_service_revenue',
                        debit: 0.00,
                        credit: $shippingAmount,
                        subOrderId: $subOrder->id,
                        orderId: $order->id,
                        narration: "Shipping fee earned by the platform for Sub-Order #{$subOrder->sub_order_number}",
                    );
                }
            }
        }

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: "order_prepaid_received_{$order->id}",
            description: "Online prepaid payment verified for Order #{$order->order_number}",
            lines: $lines,
            referenceType: Order::class,
            referenceId: $order->id,
        );

        return $this->journalPostingService->post($entryData);
    }
}
