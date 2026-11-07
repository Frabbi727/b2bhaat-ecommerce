<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\SubOrder;
use App\Services\Accounting\JournalPostingService;

class EscrowReleaseJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    /**
     * Record the transfer of held escrow funds to the seller's available wallet balance.
     */
    public function recordEscrowRelease(SubOrder $subOrder, float $amount): ?AccountingJournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0.0) {
            return null;
        }

        $order = $subOrder->order;
        $orderId = $order?->id ?? $subOrder->parent_order_id;

        $lines = [
            // 1. Debit Seller Escrow Held Payable (releases hold liability)
            new JournalLineData(
                accountSlug: 'seller_escrow_payable',
                debit: $amount,
                credit: 0.00,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Release escrow hold for delivered Sub-Order #{$subOrder->sub_order_number}",
            ),
            // 2. Credit Seller Available Wallet Payable (makes funds available for payout)
            new JournalLineData(
                accountSlug: 'seller_wallet_payable',
                debit: 0.00,
                credit: $amount,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: "Credit available wallet balance for delivered Sub-Order #{$subOrder->sub_order_number}",
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: "suborder_escrow_release_{$subOrder->id}",
            description: "Escrow release recorded for Sub-Order #{$subOrder->sub_order_number}",
            lines: $lines,
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        );

        return $this->journalPostingService->post($entryData);
    }
}
