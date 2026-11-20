<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalLine;

class GeneralLedgerService
{
    public function __construct(
        private readonly AccountResolverService $accountResolver,
    ) {}

    /**
     * Calculate the current ledger balance for a specific account.
     */
    public function getBalanceForAccount(int $accountId, ?int $sellerId = null): float
    {
        $account = AccountingAccount::findOrFail($accountId);

        $query = AccountingJournalLine::query()
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->where('accounting_journal_lines.account_id', $accountId)
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ]);

        if ($sellerId !== null) {
            $query->where('accounting_journal_lines.seller_id', $sellerId);
        }

        $totals = $query->selectRaw(
            'COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit, COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit'
        )->first();

        $debit = (float) ($totals->total_debit ?? 0.0);
        $credit = (float) ($totals->total_credit ?? 0.0);

        $normalBalance = $account->normal_balance instanceof NormalBalance
            ? $account->normal_balance
            : NormalBalance::tryFrom((string) $account->normal_balance);

        $balance = $normalBalance === NormalBalance::CREDIT
            ? round($credit - $debit, 2)
            : round($debit - $credit, 2);

        return $balance == 0.0 ? 0.00 : $balance;
    }

    /**
     * Calculate the current ledger balance for an account resolved by system slug.
     */
    public function getBalanceForSlug(string $accountSlug, ?int $sellerId = null): float
    {
        $account = $this->accountResolver->resolveBySlug($accountSlug);

        return $this->getBalanceForAccount($account->id, $sellerId);
    }
}
