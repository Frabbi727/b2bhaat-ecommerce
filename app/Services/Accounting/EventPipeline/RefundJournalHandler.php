<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Accounting\JournalPostingService;

class RefundJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
        protected CommissionRecognitionJournalHandler $commissionRecognition,
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

        // 2. Reverse the commission: from the deferred account while not yet earned, otherwise from income (ACC-11)
        array_push($lines, ...$this->commissionRecognition->commissionReversalLines(
            $subOrder,
            (float) $subOrder->commission_amount,
            $orderId,
            "Reverse commission for cancelled Sub-Order #{$subOrder->sub_order_number}",
        ));

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
            entryDate: JournalEntryData::businessDate(),
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
            // Review R-2: undo both halves of the delivery charge, commission and the platform's coupon share, so a
            // coupon bigger than the commission is taken back too. The wallet moves by the net, as at delivery.
            $commission = round((float) $subOrder->commission_amount, 2);
            $discount = round($commission - (float) $selfCollected->amount, 2);
            array_push($lines, ...$this->selfCollectedGiveBackLines(
                $subOrder,
                $commission,
                $discount,
                "returned self-collected COD for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            ));
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

            // 2. Reverse the commission: from income once earned, or the deferred account if it never moved (ACC-11)
            array_push($lines, ...$this->commissionRecognition->commissionReversalLines(
                $subOrder,
                (float) $subOrder->commission_amount,
                $orderId,
                "Reverse platform commission for returned Sub-Order #{$subOrder->sub_order_number}",
            ));

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
            entryDate: JournalEntryData::businessDate(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: "suborder_return_refund_{$subOrder->id}",
            description: "Returned sub-order refund processed for Sub-Order #{$subOrder->sub_order_number}: {$reason}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * PAY-4: a return of some items. The seller gives up the items' price less their commission share (from money
     * on hold, or from the balance once released); the commission share comes back out of the books; the coupon
     * share is no longer spent; the buyer is owed the rest. The last return of a parcel also reverses the delivery
     * fee (courier fee from shipping revenue; a self-delivery fee is already inside the seller amount).
     *
     * @param  array{seller: float, commission: float, discount: float, refund: float, courier_fee: float, completes: bool}  $amounts
     */
    public function recordPartialReturnRefund(SubOrder $subOrder, ReturnRequest $returnRequest, bool $released, array $amounts, string $reason): ?AccountingJournalEntry
    {
        $orderId = $subOrder->parent_order_id;
        $customerId = $subOrder->order?->customer_id;
        $label = "items returned from Sub-Order #{$subOrder->sub_order_number}";
        $lines = [];

        if ($amounts['seller'] > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: $released ? 'seller_wallet_payable' : 'seller_escrow_payable',
                debit: round($amounts['seller'], 2),
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: ($released ? 'Take back released money for ' : 'Reduce money on hold for ').$label.": {$reason}",
            );
        }

        array_push($lines, ...$this->commissionRecognition->commissionReversalLines(
            $subOrder,
            $amounts['commission'],
            $orderId,
            "Give back commission on {$label}",
        ));

        if ($amounts['courier_fee'] > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'shipping_service_revenue',
                debit: round($amounts['courier_fee'], 2),
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Reverse delivery fee: the whole of Sub-Order #{$subOrder->sub_order_number} came back",
            );
        }

        if ($amounts['discount'] > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'promotional_discount_expense',
                credit: round($amounts['discount'], 2),
                customerId: $customerId,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Coupon share of {$label}",
            );
        }

        if ($amounts['refund'] > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'customer_refund_payable',
                credit: round($amounts['refund'], 2),
                customerId: $customerId,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Refund owed to the buyer for {$label}: {$reason}",
            );
        }

        if ($lines === []) {
            return null;
        }

        return $this->journalPostingService->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: "return_request_refund_{$returnRequest->id}",
            description: "Refund for {$label}: {$reason}",
            lines: $lines,
            referenceType: ReturnRequest::class,
            referenceId: $returnRequest->id,
        ));
    }

    /**
     * PAY-4: items returned from a parcel whose COD cash the seller kept. Mirrors the whole-parcel case: the
     * commission charge on those items (less their coupon share) goes back to the seller's balance.
     */
    /**
     * PAY-4 / review R-2: give back the commission share of returned items and take back the platform's coupon
     * share, mirroring the delivery journal line for line. The net may go either way.
     */
    public function recordSelfCollectedPartialReturn(SubOrder $subOrder, ReturnRequest $returnRequest, float $commission, float $discount, string $reason): ?AccountingJournalEntry
    {
        $label = "items returned from self-collected COD Sub-Order #{$subOrder->sub_order_number}: {$reason}";
        $lines = $this->selfCollectedGiveBackLines($subOrder, round($commission, 2), round($discount, 2), $label);

        if ($lines === []) {
            return null;
        }

        return $this->journalPostingService->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: AccountingEvent::REFUND_PROCESSED->value,
            idempotencyKey: "return_request_cod_giveback_{$returnRequest->id}",
            description: "Commission and coupon share settled for {$label}",
            lines: $lines,
            referenceType: ReturnRequest::class,
            referenceId: $returnRequest->id,
        ));
    }

    /**
     * Reverse of the self-collected COD delivery journal: Dr commission revenue / Cr seller wallet for the
     * commission, Dr seller wallet / Cr promotional discount expense for the coupon share.
     *
     * @return list<JournalLineData>
     */
    private function selfCollectedGiveBackLines(SubOrder $subOrder, float $commission, float $discount, string $label): array
    {
        $lines = [];
        $line = fn (string $slug, float $debit, float $credit, string $narration): JournalLineData => new JournalLineData(
            accountSlug: $slug,
            debit: $debit,
            credit: $credit,
            sellerId: $subOrder->seller_id,
            subOrderId: $subOrder->id,
            orderId: $subOrder->parent_order_id,
            narration: $narration,
        );

        if ($commission > 0.0) {
            $lines[] = $line('commission_revenue', $commission, 0.00, "Reverse commission on {$label}");
            $lines[] = $line('seller_wallet_payable', 0.00, $commission, "Give back commission charge on {$label}");
        }

        if ($discount > 0.0) {
            $lines[] = $line('seller_wallet_payable', $discount, 0.00, "Take back platform coupon share on {$label}");
            $lines[] = $line('promotional_discount_expense', 0.00, $discount, "Reverse platform-funded coupon share on {$label}");
        }

        return $lines;
    }
}
