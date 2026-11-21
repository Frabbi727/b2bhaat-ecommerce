<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\SellerInvoice;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F5 (2026-11-20 finance audit): one seller's money for the admin, on one page, never mixed: what we owe them,
 * what they owe us, what we have paid them, and their bills. Balances come from the ledger through
 * SellerLedgerStatementService, the same figures the seller sees on their own statement.
 */
class SellerMoneyService
{
    public function __construct(private readonly SellerLedgerStatementService $statement) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $sellerId): array
    {
        $ledger = $this->statement->summary($sellerId);
        $netPoisha = (int) round(($ledger['escrow_held'] + $ledger['available_balance'] + $ledger['pending_payouts']) * 100);

        $bills = SellerInvoice::query()
            ->where('seller_id', $sellerId)
            ->whereIn('status', [SellerInvoice::STATUS_UNPAID, SellerInvoice::STATUS_PENDING_VERIFICATION])
            ->orderBy('due_date')
            ->get(['id', 'invoice_number', 'invoice_type', 'total_amount', 'status', 'due_date']);

        $unbookedPoisha = (int) round($bills
            ->whereIn('invoice_type', MoneyOverviewService::UNBOOKED_BILL_TYPES)
            ->sum(fn (SellerInvoice $bill): float => (float) $bill->total_amount) * 100);

        $lastPayout = WalletTransaction::query()
            ->whereHas('wallet', fn ($query) => $query->where('seller_id', $sellerId))
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_DISBURSED)
            ->latest('id')
            ->first(['id', 'amount', 'updated_at']);

        $today = Carbon::now(config('app.display_timezone'))->toDateString();

        return [
            'owed_to_them' => [
                'total' => max($netPoisha, 0) / 100.0,
                'on_hold' => $ledger['escrow_held'],
                'ready' => $ledger['available_balance'],
                'requested' => $ledger['pending_payouts'],
            ],
            'they_owe_us' => [
                'total' => (max(-$netPoisha, 0) + $unbookedPoisha) / 100.0,
                'from_balance' => max(-$netPoisha, 0) / 100.0,
                'unpaid_bills' => $unbookedPoisha / 100.0,
            ],
            'paid_to_them' => [
                'total' => $ledger['total_withdrawn'],
                'last_amount' => $lastPayout ? (float) $lastPayout->amount : null,
                'last_date' => $lastPayout?->updated_at?->timezone(config('app.display_timezone'))->format('d M Y'),
            ],
            'earned' => $ledger['net_earnings'],
            'fees_paid_from_balance' => $ledger['fees_paid_from_balance'],
            'bills' => $bills->map(fn (SellerInvoice $bill): array => [
                'number' => $bill->invoice_number,
                'type' => match ($bill->invoice_type) {
                    SellerInvoice::TYPE_SUBSCRIPTION_FEE => 'Plan fee',
                    SellerInvoice::TYPE_LISTING_PACK => 'Listing pack',
                    SellerInvoice::TYPE_COD_COMMISSION_DEFICIT => 'Commission on cash they collected',
                    default => 'Other fee',
                },
                'amount' => (float) $bill->total_amount,
                'due' => $bill->due_date?->format('d M Y'),
                'is_overdue' => $bill->status === SellerInvoice::STATUS_UNPAID && $bill->due_date !== null && $bill->due_date->toDateString() < $today,
                'is_waiting_check' => $bill->status === SellerInvoice::STATUS_PENDING_VERIFICATION,
                'in_balance' => $bill->invoice_type === SellerInvoice::TYPE_COD_COMMISSION_DEFICIT,
            ])->all(),
            'is_consistent' => $ledger['is_consistent'],
        ];
    }

    /**
     * The seller's money movements, newest first, one row per journal entry, each with what it changed and the
     * balance after it (on hold + ready + requested, so a release from on hold to ready shows no change).
     *
     * @return list<array{entry_id: int, entry_number: string, date: string, event: string, description: string, sub_order_number: ?string, change: float, moved: bool, balance_after: float}>
     */
    public function timeline(int $sellerId, int $limit = 50): array
    {
        $slugs = [SellerBalanceService::ESCROW, SellerBalanceService::WALLET, SellerBalanceService::RESERVED];

        $rows = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('sub_orders', 'sub_orders.id', '=', 'lines.sub_order_id')
            ->where('lines.seller_id', $sellerId)
            ->whereIn('entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value])
            ->whereIn('accounts.system_slug', $slugs)
            ->groupBy('entries.id', 'entries.journal_number', 'entries.entry_date', 'entries.financial_event', 'entries.description')
            ->select('entries.id', 'entries.journal_number', 'entries.entry_date', 'entries.financial_event', 'entries.description')
            ->selectRaw('MAX(sub_orders.sub_order_number) as sub_order_number')
            ->selectRaw('COALESCE(SUM(lines.credit), 0) - COALESCE(SUM(lines.debit), 0) as net_change')
            ->selectRaw('COALESCE(SUM(lines.credit), 0) + COALESCE(SUM(lines.debit), 0) as moved')
            ->orderByDesc('entries.entry_date')
            ->orderByDesc('entries.id')
            ->limit($limit)
            ->get();

        $ledger = $this->statement->summary($sellerId);
        $balancePoisha = (int) round(($ledger['escrow_held'] + $ledger['available_balance'] + $ledger['pending_payouts']) * 100);

        $timeline = [];
        foreach ($rows as $row) {
            $changePoisha = (int) round(((float) $row->net_change) * 100);

            $timeline[] = [
                'entry_id' => (int) $row->id,
                'entry_number' => (string) $row->journal_number,
                'date' => Carbon::parse($row->entry_date)->format('d M Y'),
                'event' => self::eventLabel((string) $row->financial_event),
                'description' => (string) $row->description,
                'sub_order_number' => $row->sub_order_number,
                'change' => $changePoisha / 100.0,
                'moved' => $changePoisha === 0 && (float) $row->moved > 0,
                'balance_after' => $balancePoisha / 100.0,
            ];

            $balancePoisha -= $changePoisha;
        }

        return $timeline;
    }

    public static function eventLabel(string $event): string
    {
        return match ($event) {
            AccountingEvent::COD_DELIVERED->value, AccountingEvent::ORDER_PREPAID_RECEIVED->value => 'Order delivered (money on hold)',
            AccountingEvent::COD_SELF_DELIVERED->value => 'Delivered it themselves and kept the cash (owes our commission)',
            AccountingEvent::ESCROW_RELEASED->value => 'Released after the 48-hour check',
            AccountingEvent::REFUND_PROCESSED->value => 'Refund or return taken off',
            AccountingEvent::PAYOUT_REQUESTED->value => 'Asked to withdraw',
            AccountingEvent::PAYOUT_REJECTED->value => 'Withdrawal turned down',
            AccountingEvent::PAYOUT_DISBURSED->value => 'Paid to them',
            AccountingEvent::COD_DEFICIT_PAID->value => 'Paid us what they owed',
            AccountingEvent::SUBSCRIPTION_PAID->value => 'Plan fee paid from balance',
            AccountingEvent::LISTING_PACK_PAID->value => 'Listing pack paid from balance',
            AccountingEvent::SELF_DELIVERY_FEE_MOVED->value => 'Delivery charge passed to them',
            AccountingEvent::COMMISSION_EARNED->value => 'Our commission',
            AccountingEvent::MANUAL_JOURNAL->value => 'Manual adjustment',
            'reversal' => 'Correction (reverses an earlier entry)',
            default => str($event)->replace('_', ' ')->ucfirst()->toString(),
        };
    }
}
