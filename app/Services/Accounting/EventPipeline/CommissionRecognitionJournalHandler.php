<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\SubOrder;
use App\Services\Accounting\JournalPostingService;

/**
 * ACC-11: commission on a prepaid order is held as `deferred_commission_revenue` when the payment arrives and becomes
 * `commission_revenue` only when the parcel is delivered. Orders paid before this change already booked their
 * commission as income, so they have no deferred balance and nothing moves for them.
 */
class CommissionRecognitionJournalHandler
{
    public const DEFERRED_ACCOUNT = 'deferred_commission_revenue';

    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    /**
     * Commission for this sub-order still waiting in the deferred account.
     */
    public function deferredBalanceFor(SubOrder $subOrder): float
    {
        $totals = AccountingJournalLine::query()
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->join('accounting_accounts', 'accounting_journal_lines.account_id', '=', 'accounting_accounts.id')
            ->where('accounting_accounts.system_slug', self::DEFERRED_ACCOUNT)
            ->where('accounting_journal_lines.sub_order_id', $subOrder->id)
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ])
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit, COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit')
            ->first();

        $balance = round((float) ($totals->total_credit ?? 0) - (float) ($totals->total_debit ?? 0), 2);

        return $balance > 0.0 ? $balance : 0.0;
    }

    /**
     * Moves the sub-order's deferred commission to income once it is delivered. Posts nothing when there is none.
     */
    public function recordCommissionEarned(SubOrder $subOrder): ?AccountingJournalEntry
    {
        $amount = $this->deferredBalanceFor($subOrder);

        if ($amount <= 0.0) {
            return null;
        }

        $orderId = $subOrder->order?->id ?? $subOrder->parent_order_id;

        return $this->journalPostingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::COMMISSION_EARNED->value,
            idempotencyKey: "suborder_commission_earned_{$subOrder->id}",
            description: "Commission earned on delivery of Sub-Order #{$subOrder->sub_order_number}",
            lines: [
                new JournalLineData(
                    accountSlug: self::DEFERRED_ACCOUNT,
                    debit: $amount,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Deferred commission released for delivered Sub-Order #{$subOrder->sub_order_number}",
                ),
                new JournalLineData(
                    accountSlug: 'commission_revenue',
                    credit: $amount,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $orderId,
                    narration: "Commission earned on delivered Sub-Order #{$subOrder->sub_order_number}",
                ),
            ],
            referenceType: SubOrder::class,
            referenceId: $subOrder->id,
        ));
    }

    /**
     * Lines that take a cancelled or returned sub-order's commission back out of the books: from the deferred account
     * while it is still there, otherwise from income.
     *
     * @return list<JournalLineData>
     */
    public function commissionReversalLines(SubOrder $subOrder, float $commission, ?int $orderId, string $narration): array
    {
        $commission = round($commission, 2);

        if ($commission <= 0.0) {
            return [];
        }

        $fromDeferred = min($commission, $this->deferredBalanceFor($subOrder));
        $fromRevenue = round($commission - $fromDeferred, 2);
        $lines = [];

        if ($fromDeferred > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: self::DEFERRED_ACCOUNT,
                debit: $fromDeferred,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: $narration.' (not yet earned)',
            );
        }

        if ($fromRevenue > 0.0) {
            $lines[] = new JournalLineData(
                accountSlug: 'commission_revenue',
                debit: $fromRevenue,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $orderId,
                narration: $narration,
            );
        }

        return $lines;
    }
}
