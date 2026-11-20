<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use Illuminate\Support\Facades\DB;

/**
 * What the platform owes sellers and what sellers owe the platform, read from the ledger and kept apart.
 *
 * A seller's money sits in three liability accounts: escrow (held after delivery), wallet (ready to withdraw) and
 * reserved payouts (requested, not yet paid). A seller's wallet can go below zero, for example when they kept a
 * buyer's cash and owe its commission. Summing an account across all sellers lets one seller's debt cancel another
 * seller's money, so the totals here net each seller's own three accounts first and only then add up the sellers
 * who are owed and, separately, the sellers who owe (F1 in the 2026-11-20 finance audit).
 */
class SellerBalanceService
{
    public const ESCROW = 'seller_escrow_payable';

    public const WALLET = 'seller_wallet_payable';

    public const RESERVED = 'reserved_payout_payable';

    public function __construct(
        private readonly AccountResolverService $accountResolver,
    ) {}

    /**
     * Each seller's ledger balance in the three seller accounts, in one query.
     *
     * @return array<int, array{escrow: float, wallet: float, reserved: float, net: float}>
     */
    public function balancesBySeller(?string $asOfDate = null): array
    {
        $accountIds = [
            $this->accountResolver->resolveBySlug(self::ESCROW)->id => 'escrow',
            $this->accountResolver->resolveBySlug(self::WALLET)->id => 'wallet',
            $this->accountResolver->resolveBySlug(self::RESERVED)->id => 'reserved',
        ];

        $rows = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value])
            ->whereIn('accounting_journal_lines.account_id', array_keys($accountIds))
            ->whereNotNull('accounting_journal_lines.seller_id')
            ->when($asOfDate, fn ($query) => $query->whereDate('accounting_journal_entries.entry_date', '<=', $asOfDate))
            ->groupBy('accounting_journal_lines.seller_id', 'accounting_journal_lines.account_id')
            ->select('accounting_journal_lines.seller_id', 'accounting_journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) - COALESCE(SUM(accounting_journal_lines.debit), 0) as balance')
            ->get();

        /** @var array<int, array{escrow: int, wallet: int, reserved: int}> $poisha */
        $poisha = [];
        foreach ($rows as $row) {
            $sellerId = (int) $row->seller_id;
            $poisha[$sellerId] ??= ['escrow' => 0, 'wallet' => 0, 'reserved' => 0];
            $poisha[$sellerId][$accountIds[(int) $row->account_id]] += (int) round(((float) $row->balance) * 100);
        }

        $balances = [];
        foreach ($poisha as $sellerId => $parts) {
            $balances[$sellerId] = [
                'escrow' => $parts['escrow'] / 100.0,
                'wallet' => $parts['wallet'] / 100.0,
                'reserved' => $parts['reserved'] / 100.0,
                'net' => ($parts['escrow'] + $parts['wallet'] + $parts['reserved']) / 100.0,
            ];
        }

        return $balances;
    }

    /**
     * The two totals, never netted against each other: money owed to sellers who are in credit, and money owed by
     * sellers who are in debit. Their difference always equals the three accounts' combined ledger balance.
     *
     * @return array{owed_to_sellers: float, owed_by_sellers: float, sellers_owed: int, sellers_owing: int, net: float}
     */
    public function split(?string $asOfDate = null): array
    {
        $owedToPoisha = 0;
        $owedByPoisha = 0;
        $sellersOwed = 0;
        $sellersOwing = 0;

        foreach ($this->balancesBySeller($asOfDate) as $balance) {
            $netPoisha = (int) round($balance['net'] * 100);

            if ($netPoisha > 0) {
                $owedToPoisha += $netPoisha;
                $sellersOwed++;
            } elseif ($netPoisha < 0) {
                $owedByPoisha -= $netPoisha;
                $sellersOwing++;
            }
        }

        return [
            'owed_to_sellers' => $owedToPoisha / 100.0,
            'owed_by_sellers' => $owedByPoisha / 100.0,
            'sellers_owed' => $sellersOwed,
            'sellers_owing' => $sellersOwing,
            'net' => ($owedToPoisha - $owedByPoisha) / 100.0,
        ];
    }
}
