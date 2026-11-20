<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingJournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * ACC-19: a seller's statement totals, taken from the seller's lines in the general ledger rather than from the
 * wallet table. A reversal counts against the event it reverses, so a cancelled payout or release drops out.
 *
 * The figures always tie together: net earnings + paid in − fees paid from balance − withdrawn − payouts waiting = available.
 */
class SellerLedgerStatementService
{
    private const WALLET = 'seller_wallet_payable';

    private const ESCROW = 'seller_escrow_payable';

    private const RESERVED = 'reserved_payout_payable';

    /** Events that move money between the balance and payouts; they are not earnings. */
    private const PAYOUT_EVENTS = [
        AccountingEvent::PAYOUT_REQUESTED->value,
        AccountingEvent::PAYOUT_REJECTED->value,
    ];

    /** MON-11: money the seller paid in to clear what they owed; not earnings. */
    private const PAID_IN_EVENTS = [
        AccountingEvent::COD_DEFICIT_PAID->value,
    ];

    /** Platform fees a seller can pay from their balance. */
    private const FEE_EVENTS = [
        AccountingEvent::SUBSCRIPTION_PAID->value,
        AccountingEvent::LISTING_PACK_PAID->value,
    ];

    /**
     * @return array{
     *     available_balance: float,
     *     escrow_held: float,
     *     pending_payouts: float,
     *     net_earnings: float,
     *     fees_paid_from_balance: float,
     *     total_withdrawn: float,
     *     paid_in: float,
     *     is_consistent: bool
     * }
     */
    public function summary(int $sellerId): array
    {
        $rows = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('accounting_journal_entries as originals', function ($join): void {
                $join->on('originals.id', '=', 'entries.reference_id')
                    ->where('entries.financial_event', '=', 'reversal')
                    ->where('entries.reference_type', '=', AccountingJournalEntry::class);
            })
            ->where('lines.seller_id', $sellerId)
            ->whereIn('entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value])
            ->whereIn('accounts.system_slug', [self::WALLET, self::ESCROW, self::RESERVED])
            ->groupBy('accounts.system_slug', DB::raw('COALESCE(originals.financial_event, entries.financial_event)'))
            ->select('accounts.system_slug')
            ->selectRaw('COALESCE(originals.financial_event, entries.financial_event) as event')
            ->selectRaw('COALESCE(SUM(lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(lines.credit), 0) as total_credit')
            ->get();

        $available = 0;
        $escrow = 0;
        $pending = 0;
        $earnings = 0;
        $fees = 0;
        $withdrawn = 0;
        $paidIn = 0;

        foreach ($rows as $row) {
            // All three accounts are liabilities to the seller: credit increases what is owed
            $netPoisha = (int) round(((float) $row->total_credit - (float) $row->total_debit) * 100);

            match ($row->system_slug) {
                self::ESCROW => $escrow += $netPoisha,
                self::RESERVED => $pending += $netPoisha,
                default => $available += $netPoisha,
            };

            if ($row->system_slug === self::RESERVED && $row->event === AccountingEvent::PAYOUT_DISBURSED->value) {
                $withdrawn -= $netPoisha;
            }

            if ($row->system_slug === self::WALLET) {
                if (in_array($row->event, self::FEE_EVENTS, true)) {
                    $fees -= $netPoisha;
                } elseif (in_array($row->event, self::PAID_IN_EVENTS, true)) {
                    $paidIn += $netPoisha;
                } elseif (! in_array($row->event, self::PAYOUT_EVENTS, true)) {
                    $earnings += $netPoisha;
                }
            }
        }

        return [
            'available_balance' => $this->toTaka($available),
            'escrow_held' => $this->toTaka($escrow),
            'pending_payouts' => $this->toTaka($pending),
            'net_earnings' => $this->toTaka($earnings),
            'fees_paid_from_balance' => $this->toTaka($fees),
            'total_withdrawn' => $this->toTaka($withdrawn),
            'paid_in' => $this->toTaka($paidIn),
            'is_consistent' => $earnings + $paidIn - $fees - $withdrawn - $pending === $available,
        ];
    }

    private function toTaka(int $poisha): float
    {
        return $poisha === 0 ? 0.0 : round($poisha / 100, 2);
    }
}
