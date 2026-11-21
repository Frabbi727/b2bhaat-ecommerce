<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Refund;
use App\Models\SellerInvoice;
use App\Models\SellerProfile;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase B of the 2026-11-20 finance audit: every figure on the admin "Money" page, in plain words.
 *
 * Balances (money we hold, what we owe sellers, what sellers owe us, refunds owed, courier cash) are read from the
 * ledger as of the end date. Income and costs cover the period and reuse the income statement, so the page and the
 * P&L can never disagree. The only figure not in the ledger is unpaid seller bills (F4): subscription, listing and
 * manual bills are booked only when paid, so they come from the bills table and are labelled that way. Cash-on-
 * delivery commission bills are left out because that debt is already a negative seller wallet.
 */
class MoneyOverviewService
{
    public const CASH_ACCOUNTS = [
        'cash_on_hand' => 'Cash in hand',
        'bank_operating' => 'Bank',
        'gateway_clearing' => 'bKash / Nagad wallet',
    ];

    /** Bill types that are not in the books until paid. */
    public const UNBOOKED_BILL_TYPES = [
        SellerInvoice::TYPE_SUBSCRIPTION_FEE,
        SellerInvoice::TYPE_LISTING_PACK,
        SellerInvoice::TYPE_MANUAL_FEE,
    ];

    /** A payout request or courier cash older than this many days needs follow-up. */
    public const PAYOUT_WAIT_DAYS = 3;

    public const COURIER_WAIT_DAYS = 7;

    public const LAST_CHECK_CACHE_KEY = 'accounting.last_reconciliation';

    public function __construct(
        private readonly SellerBalanceService $sellerBalances,
        private readonly FinancialStatementService $statements,
        private readonly SettlementService $settlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $from, string $to): array
    {
        $balances = $this->accountBalances($to, [...array_keys(self::CASH_ACCOUNTS), 'customer_refund_payable', 'courier_cod_receivable']);

        $cash = [];
        foreach (self::CASH_ACCOUNTS as $slug => $label) {
            $cash[] = ['label' => $label, 'amount' => $balances[$slug] ?? 0.0];
        }

        $owedTo = $this->owedToSellers($to);
        $owedBy = $this->owedBySellers($to);

        $income = $this->statements->getIncomeStatement($from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'money_we_hold' => [
                'total' => $this->sum(array_column($cash, 'amount')),
                'parts' => $cash,
            ],
            'owed_to_sellers' => $owedTo,
            'owed_by_sellers' => $owedBy,
            'refunds_owed' => $balances['customer_refund_payable'] ?? 0.0,
            'courier_cash' => $balances['courier_cod_receivable'] ?? 0.0,
            'income' => [
                'total' => $income['revenues']['total'],
                'parts' => $this->statementParts($income['revenues']['accounts']),
            ],
            'costs' => [
                'total' => $income['expenses']['total'],
                'parts' => $this->statementParts($income['expenses']['accounts']),
            ],
            'profit' => $income['net_income'],
        ];
    }

    /**
     * What needs someone's attention today, whatever period the page shows.
     *
     * @return array<string, mixed>
     */
    public function followUp(): array
    {
        $today = Carbon::now(config('app.display_timezone'))->toDateString();

        $overdueBills = SellerInvoice::query()
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->whereDate('due_date', '<', $today)
            ->groupBy('seller_id')
            ->selectRaw('seller_id, COUNT(*) as bills, COALESCE(SUM(total_amount), 0) as total, MIN(due_date) as oldest_due')
            ->orderBy('oldest_due')
            ->limit(20)
            ->get();

        $owing = collect($this->sellerBalances->balancesBySeller())
            ->filter(fn (array $balance): bool => $balance['net'] < 0)
            ->sortBy('net')
            ->take(20);

        $sellerIds = $overdueBills->pluck('seller_id')->merge($owing->keys())->unique()->all();
        $names = $this->sellerNames($sellerIds);

        $payoutCutoff = now()->subDays(self::PAYOUT_WAIT_DAYS);
        $waitingPayouts = WalletTransaction::query()
            ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
            ->where('status', WalletTransaction::STATUS_REQUESTED)
            ->where('created_at', '<', $payoutCutoff)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total, MIN(created_at) as oldest')
            ->first();

        $pendingRefunds = Refund::query()
            ->where('status', Refund::STATUS_PENDING)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total, MIN(created_at) as oldest')
            ->first();

        $courierCutoff = Carbon::now(config('app.display_timezone'))->subDays(self::COURIER_WAIT_DAYS)->toDateString();
        $oldCourierCash = $this->settlements->unsettledCourierParcels()
            ->filter(fn (array $parcel): bool => $parcel['delivered_on'] < $courierCutoff);

        return [
            'overdue_bills' => $overdueBills->map(fn ($row): array => [
                'seller_id' => (int) $row->seller_id,
                'seller' => $names[(int) $row->seller_id] ?? 'Seller #'.$row->seller_id,
                'bills' => (int) $row->bills,
                'total' => round((float) $row->total, 2),
                'oldest_due' => Carbon::parse($row->oldest_due)->format('d M Y'),
            ])->values()->all(),
            'sellers_owing' => $owing->map(fn (array $balance, int $sellerId): array => [
                'seller_id' => $sellerId,
                'seller' => $names[$sellerId] ?? 'Seller #'.$sellerId,
                'total' => round(-$balance['net'], 2),
            ])->values()->all(),
            'waiting_payouts' => [
                'count' => (int) ($waitingPayouts->n ?? 0),
                'total' => round((float) ($waitingPayouts->total ?? 0), 2),
                'oldest' => $waitingPayouts?->oldest ? Carbon::parse($waitingPayouts->oldest)->timezone(config('app.display_timezone'))->format('d M Y') : null,
            ],
            'pending_refunds' => [
                'count' => (int) ($pendingRefunds->n ?? 0),
                'total' => round((float) ($pendingRefunds->total ?? 0), 2),
                'oldest' => $pendingRefunds?->oldest ? Carbon::parse($pendingRefunds->oldest)->timezone(config('app.display_timezone'))->format('d M Y') : null,
            ],
            'old_courier_cash' => [
                'count' => $oldCourierCash->count(),
                'total' => $this->sum($oldCourierCash->pluck('amount')->all()),
                'oldest' => $oldCourierCash->isEmpty() ? null : Carbon::parse($oldCourierCash->min('delivered_on'))->format('d M Y'),
            ],
            'last_check' => Cache::get(self::LAST_CHECK_CACHE_KEY),
        ];
    }

    /**
     * Sellers in credit, split into money on hold, ready to withdraw and payout requested.
     *
     * @return array{total: float, sellers: int, on_hold: float, ready: float, requested: float}
     */
    private function owedToSellers(string $asOf): array
    {
        $onHold = 0;
        $ready = 0;
        $requested = 0;
        $sellers = 0;

        foreach ($this->sellerBalances->balancesBySeller($asOf) as $balance) {
            if ((int) round($balance['net'] * 100) <= 0) {
                continue;
            }

            $sellers++;
            $onHold += (int) round($balance['escrow'] * 100);
            $ready += (int) round($balance['wallet'] * 100);
            $requested += (int) round($balance['reserved'] * 100);
        }

        return [
            'total' => ($onHold + $ready + $requested) / 100.0,
            'sellers' => $sellers,
            'on_hold' => $onHold / 100.0,
            'ready' => $ready / 100.0,
            'requested' => $requested / 100.0,
        ];
    }

    /**
     * Sellers below zero in the books, plus their unpaid bills that are not in the books yet.
     *
     * @return array{total: float, from_balances: float, sellers: int, unpaid_bills: float, bills: int}
     */
    private function owedBySellers(string $asOf): array
    {
        $split = $this->sellerBalances->split($asOf);
        $endOfDay = Carbon::parse($asOf, config('app.display_timezone'))->endOfDay()->setTimezone(config('app.timezone'));

        // A bill counts if it was issued by the end date and was still not paid at that time
        $bills = SellerInvoice::query()
            ->whereIn('invoice_type', self::UNBOOKED_BILL_TYPES)
            ->where('created_at', '<=', $endOfDay)
            ->where(fn ($query) => $query
                ->whereIn('status', [SellerInvoice::STATUS_UNPAID, SellerInvoice::STATUS_PENDING_VERIFICATION])
                ->orWhere(fn ($paid) => $paid->where('status', SellerInvoice::STATUS_PAID)->where('paid_at', '>', $endOfDay)))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount), 0) as total')
            ->first();

        $unpaidBills = round((float) ($bills->total ?? 0), 2);

        return [
            'total' => round($split['owed_by_sellers'] + $unpaidBills, 2),
            'from_balances' => $split['owed_by_sellers'],
            'sellers' => $split['sellers_owing'],
            'unpaid_bills' => $unpaidBills,
            'bills' => (int) ($bills->n ?? 0),
        ];
    }

    /**
     * Each account's balance in its normal direction (assets debit, liabilities credit) as of the date, in one query.
     *
     * @param  list<string>  $slugs
     * @return array<string, float>
     */
    private function accountBalances(string $asOf, array $slugs): array
    {
        return DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->join('accounting_accounts', 'accounting_journal_lines.account_id', '=', 'accounting_accounts.id')
            ->whereIn('accounting_journal_entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value])
            ->whereIn('accounting_accounts.system_slug', $slugs)
            ->whereDate('accounting_journal_entries.entry_date', '<=', $asOf)
            ->groupBy('accounting_accounts.system_slug', 'accounting_accounts.normal_balance')
            ->select('accounting_accounts.system_slug', 'accounting_accounts.normal_balance')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) as debit, COALESCE(SUM(accounting_journal_lines.credit), 0) as credit')
            ->get()
            ->mapWithKeys(function ($row): array {
                $poisha = (int) round(((float) $row->debit - (float) $row->credit) * 100);

                return [$row->system_slug => ($row->normal_balance === 'credit' ? -$poisha : $poisha) / 100.0];
            })
            ->all();
    }

    /**
     * @param  list<array{code: string, name: string, amount: float, is_contra: bool}>  $rows
     * @return list<array{label: string, amount: float}>
     */
    private function statementParts(array $rows): array
    {
        return array_values(array_map(
            fn (array $row): array => ['label' => $row['name'], 'amount' => $row['is_contra'] ? -$row['amount'] : $row['amount']],
            array_filter($rows, fn (array $row): bool => $row['amount'] != 0.0),
        ));
    }

    /**
     * @param  list<int>  $sellerIds
     * @return array<int, string>
     */
    private function sellerNames(array $sellerIds): array
    {
        if ($sellerIds === []) {
            return [];
        }

        return SellerProfile::query()
            ->join('users', 'users.id', '=', 'seller_profiles.user_id')
            ->whereIn('seller_profiles.user_id', $sellerIds)
            ->selectRaw('seller_profiles.user_id, COALESCE(seller_profiles.store_name, users.name) as label')
            ->pluck('label', 'seller_profiles.user_id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->all();
    }

    /**
     * @param  list<float|int|string>  $amounts
     */
    private function sum(array $amounts): float
    {
        return array_sum(array_map(fn ($amount): int => (int) round(((float) $amount) * 100), $amounts)) / 100.0;
    }
}
