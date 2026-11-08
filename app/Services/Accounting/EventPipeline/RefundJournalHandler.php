<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Accounting\JournalPostingService;

class RefundJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    /**
     * Record accounting entries for a prepaid sub-order cancelled before delivery release.
     */
    public function recordPrepaidCancellationRefund(SubOrder $subOrder, float $amount, string $reason): ?AccountingJournalEntry
    {
        $order = $subOrder->order;
        $orderId = $order?->id ?? $subOrder->parent_order_id;
        $lines = [];

        // 1. Debit Seller Escrow Held Payable
        $escrowHeld = round(
            $amount > 0.0
                ? $amount
                : ($subOrder->is_self_delivered ? $subOrder->sellerPayableAmount() : (float) $subOrder->net_earnings),
            2
        );
        if ($escrowHeld > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'seller_escrow_payable',
                debit: $escrowHeld,
                credit: 0.00,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Reverse escrow hold for cancelled Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            );
        }

        // 2. Debit Platform Commission Revenue (reversing unearned platform commission)
        $commission = round((float) $subOrder->commission_amount, 2);
        if ($commission > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'commission_revenue',
                debit: $commission,
                credit: 0.00,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Reverse commission revenue for cancelled Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        // 3. Debit Shipping Revenue (the platform no longer earns the courier shipping fee)
        $shipping = round((float) $subOrder->shipping_amount, 2);
        if (! $subOrder->is_self_delivered && $shipping > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'shipping_service_revenue',
                debit: $shipping,
                credit: 0.00,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Reverse shipping revenue for cancelled Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        // 4. Credit Promotional Discount Expense (reversing platform-funded coupon subsidy)
        $discount = round((float) $subOrder->allocatedDiscount(), 2);
        if ($discount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'promotional_discount_expense',
                debit: 0.00,
                credit: $discount,
                customerId: $order?->customer_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Reverse promotional discount for cancelled Sub-Order #{$subOrder->sub_order_number}",
            );
        }

        // 5. Credit Customer Refund Payable (liability owed to buyer until refund disbursement)
        $paidAmount = round((float) $subOrder->paidAmount(), 2);
        if ($paidAmount > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'customer_refund_payable',
                debit: 0.00,
                credit: $paidAmount,
                customerId: $order?->customer_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Customer refund payable for cancelled Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            );
        }

        if (empty($lines)) {
            return null;
        }

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: "suborder_cancel_refund_{$subOrder->id}",
            description: "Cancellation refund processed for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * Record accounting entries for a returned sub-order.
     */
    public function recordReturnedSubOrderRefund(SubOrder $subOrder, string $reason): ?AccountingJournalEntry
    {
        $order = $subOrder->order;
        $orderId = $order?->id ?? $subOrder->parent_order_id;
        $lines = [];

        // Check if self-delivered COD with commission charge
        $selfCollected = WalletTransaction::where('sub_order_id', $subOrder->id)
            ->where('transaction_type', WalletTransaction::TYPE_COD_SELF_COLLECTED)
            ->first();

        if ($subOrder->is_self_delivered && $selfCollected !== null) {
            $commission = round((float) $selfCollected->amount, 2);
            if ($commission > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'commission_revenue',
                    debit: $commission,
                    credit: 0.00,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Reverse platform commission on returned self-collected COD for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
                );
                $lines[] = new JournalLineData(
                    accountSlug: 'seller_wallet_payable',
                    debit: 0.00,
                    credit: $commission,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Refund commission charge on returned self-collected COD for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
                );
            }
        } else {
            $releaseTx = WalletTransaction::where('sub_order_id', $subOrder->id)
                ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
                ->first();

            // If not yet released to available wallet, route through cancellation refund
            if ($releaseTx === null) {
                return $this->recordPrepaidCancellationRefund($subOrder, $subOrder->sellerPayableAmount(), $reason);
            }

            // 1. Debit Seller Available Wallet Payable (claw back released funds from available balance)
            $releasedAmount = round((float) $releaseTx->amount, 2);
            if ($releasedAmount > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'seller_wallet_payable',
                    debit: $releasedAmount,
                    credit: 0.00,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Claw back released settlement for returned Sub-Order #{$subOrder->sub_order_number}: {$reason}",
                );
            }

            // 2. Debit Platform Commission Revenue (reversing commission earned)
            $commission = round((float) $subOrder->commission_amount, 2);
            if ($commission > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'commission_revenue',
                    debit: $commission,
                    credit: 0.00,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Reverse platform commission for returned Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // 3. Debit Shipping Revenue (if the platform's courier carried it)
            $shipping = round((float) $subOrder->shipping_amount, 2);
            if (! $subOrder->is_self_delivered && $shipping > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'shipping_service_revenue',
                    debit: $shipping,
                    credit: 0.00,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Reverse shipping revenue for returned Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // 4. Credit Promotional Discount Expense (if discount applied)
            $discount = round((float) $subOrder->allocatedDiscount(), 2);
            if ($discount > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'promotional_discount_expense',
                    debit: 0.00,
                    credit: $discount,
                    customerId: $order?->customer_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Reverse promotional discount for returned Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // 5. Credit Customer Refund Payable (funds owed to customer)
            $paidAmount = round((float) $subOrder->paidAmount(), 2);
            if ($paidAmount > 0.0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'customer_refund_payable',
                    debit: 0.00,
                    credit: $paidAmount,
                    customerId: $order?->customer_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Customer refund payable for returned Sub-Order #{$subOrder->sub_order_number}: {$reason}",
                );
            }
        }

        if (empty($lines)) {
            return null;
        }

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: "suborder_return_refund_{$subOrder->id}",
            description: "Returned sub-order refund processed for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }
}
