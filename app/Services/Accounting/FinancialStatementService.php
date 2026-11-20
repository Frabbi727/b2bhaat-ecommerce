<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialStatementService
{
    /**
     * Compute the Income Statement for a given period.
     *
     * @return array{
     *     start_date: ?string,
     *     end_date: ?string,
     *     period_label: string,
     *     revenues: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     expenses: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     net_income: float,
     *     formatted_net_income: string,
     *     is_profit: bool,
     *     operating_margin_percentage: float,
     *     formatted_operating_margin: string
     * }
     */
    public function getIncomeStatement(?string $startDate = null, ?string $endDate = null): array
    {
        ['is_all_time' => $isAllTime, 'start' => $start, 'end' => $end, 'label' => $periodLabel] = $this->resolvePeriod($startDate, $endDate);

        /** @var Collection<int, object{account_id: int, total_debit: string, total_credit: string}> $totals */
        $totals = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ])
            ->when(! $isAllTime && $start !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '>=', $start))
            ->when(! $isAllTime && $end !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '<=', $end))
            ->select('accounting_journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit')
            ->groupBy('accounting_journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        // Operating Revenues (4000 series): Credit - Debit
        $revenueAccounts = AccountingAccount::query()
            ->where('type', AccountType::REVENUE)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        ['rows' => $revenueRows, 'total' => $totalRevenues] = $this->statementRows($revenueAccounts, $totals, AccountType::REVENUE);

        // Operating Expenses (5000 series): Debit - Credit
        $expenseAccounts = AccountingAccount::query()
            ->where('type', AccountType::EXPENSE)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        ['rows' => $expenseRows, 'total' => $totalExpenses] = $this->statementRows($expenseAccounts, $totals, AccountType::EXPENSE);

        $netIncome = round($totalRevenues - $totalExpenses, 2);
        $isProfit = $netIncome >= 0.0;

        $operatingMarginPercentage = $totalRevenues > 0.0
            ? round(($netIncome / $totalRevenues) * 100, 2)
            : 0.00;

        return [
            'start_date' => $start,
            'end_date' => $end,
            'period_label' => $periodLabel,
            'revenues' => [
                'accounts' => $revenueRows,
                'total' => $totalRevenues,
                'formatted_total' => $this->formatMoney($totalRevenues),
            ],
            'expenses' => [
                'accounts' => $expenseRows,
                'total' => $totalExpenses,
                'formatted_total' => $this->formatMoney($totalExpenses),
            ],
            'net_income' => $netIncome,
            'formatted_net_income' => $this->formatMoney($netIncome, signed: true),
            'is_profit' => $isProfit,
            'operating_margin_percentage' => $operatingMarginPercentage,
            'formatted_operating_margin' => number_format($operatingMarginPercentage, 2).'%',
        ];
    }

    /**
     * Compute the Balance Sheet as of a specific date.
     *
     * @return array{
     *     as_of_date: string,
     *     formatted_as_of_date: string,
     *     assets: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     liabilities: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     equity: array{
     *         capital_accounts: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>,
     *         retained_earnings: float,
     *         formatted_retained_earnings: string,
     *         current_net_income: float,
     *         formatted_current_net_income: string,
     *         total: float,
     *         formatted_total: string
     *     },
     *     total_liabilities_and_equity: float,
     *     formatted_total_liabilities_and_equity: string,
     *     is_balanced: bool,
     *     discrepancy: float,
     *     formatted_discrepancy: string
     * }
     */
    public function getBalanceSheet(?string $asOfDate = null): array
    {
        try {
            $asOf = ! empty($asOfDate)
                ? Carbon::parse($asOfDate)->toDateString()
                : now(config('app.display_timezone'))->toDateString();
        } catch (\Throwable) {
            $asOf = now(config('app.display_timezone'))->toDateString();
        }

        $formattedAsOfDate = Carbon::parse($asOf)->format('d M Y');

        /** @var Collection<int, object{account_id: int, total_debit: string, total_credit: string}> $totals */
        $totals = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ])
            ->whereDate('accounting_journal_entries.entry_date', '<=', $asOf)
            ->select('accounting_journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit')
            ->groupBy('accounting_journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        // Assets (1000 series): Debit - Credit
        $assetAccounts = AccountingAccount::query()
            ->where('type', AccountType::ASSET)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        ['rows' => $assetRows, 'total' => $totalAssets] = $this->statementRows($assetAccounts, $totals, AccountType::ASSET);

        // Liabilities (2000 series): Credit - Debit
        $liabilityAccounts = AccountingAccount::query()
            ->where('type', AccountType::LIABILITY)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        ['rows' => $liabilityRows, 'total' => $totalLiabilities] = $this->statementRows($liabilityAccounts, $totals, AccountType::LIABILITY);

        // Equity (3000 series): Credit - Debit
        $equityAccounts = AccountingAccount::query()
            ->where('type', AccountType::EQUITY)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        ['rows' => $capitalRows, 'total' => $retainedEarnings] = $this->statementRows($equityAccounts, $totals, AccountType::EQUITY);

        // Dynamic Cumulative Net Income up to asOfDate = Revenues - Expenses
        $allRevenueAccounts = AccountingAccount::query()
            ->where('type', AccountType::REVENUE)
            ->get(['id']);

        $allExpenseAccounts = AccountingAccount::query()
            ->where('type', AccountType::EXPENSE)
            ->get(['id']);

        $cumulativeRevenues = 0.0;
        foreach ($allRevenueAccounts as $account) {
            $line = $totals->get($account->id);
            if ($line) {
                $debit = (float) $line->total_debit;
                $credit = (float) $line->total_credit;
                $cumulativeRevenues = round($cumulativeRevenues + ($credit - $debit), 2);
            }
        }

        $cumulativeExpenses = 0.0;
        foreach ($allExpenseAccounts as $account) {
            $line = $totals->get($account->id);
            if ($line) {
                $debit = (float) $line->total_debit;
                $credit = (float) $line->total_credit;
                $cumulativeExpenses = round($cumulativeExpenses + ($debit - $credit), 2);
            }
        }

        $currentNetIncome = round($cumulativeRevenues - $cumulativeExpenses, 2);
        $totalEquity = round($retainedEarnings + $currentNetIncome, 2);
        $totalLiabilitiesAndEquity = round($totalLiabilities + $totalEquity, 2);

        $discrepancy = round($totalAssets - $totalLiabilitiesAndEquity, 2);
        $isBalanced = abs($discrepancy) < 0.01;

        if ($isBalanced) {
            $discrepancy = 0.00;
        }

        return [
            'as_of_date' => $asOf,
            'formatted_as_of_date' => $formattedAsOfDate,
            'assets' => [
                'accounts' => $assetRows,
                'total' => $totalAssets,
                'formatted_total' => $this->formatMoney($totalAssets),
            ],
            'liabilities' => [
                'accounts' => $liabilityRows,
                'total' => $totalLiabilities,
                'formatted_total' => $this->formatMoney($totalLiabilities),
            ],
            'equity' => [
                'capital_accounts' => $capitalRows,
                'retained_earnings' => $retainedEarnings,
                'formatted_retained_earnings' => $this->formatMoney($retainedEarnings),
                'current_net_income' => $currentNetIncome,
                'formatted_current_net_income' => $this->formatMoney($currentNetIncome, signed: true),
                'total' => $totalEquity,
                'formatted_total' => $this->formatMoney($totalEquity),
            ],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'formatted_total_liabilities_and_equity' => $this->formatMoney($totalLiabilitiesAndEquity),
            'is_balanced' => $isBalanced,
            'discrepancy' => $discrepancy,
            'formatted_discrepancy' => $this->formatMoney($discrepancy, signed: true),
        ];
    }

    /** Accounts that hold the platform's own cash. Money still with a gateway or courier is not cash yet. */
    public const CASH_ACCOUNT_SLUGS = ['cash_on_hand', 'bank_operating'];

    /** Plain names for the business events that move cash. */
    private const CASH_EVENT_LABELS = [
        'order_prepaid_received' => 'Customer payments received',
        'cod_delivered' => 'Cash on delivery collected',
        'cod_self_delivered' => 'Cash on delivery collected by sellers',
        'escrow_released' => 'Escrow released to sellers',
        'refund_processed' => 'Refunds',
        'subscription_paid' => 'Subscription payments',
        'listing_pack_paid' => 'Listing pack payments',
        'cod_deficit_paid' => 'COD commission paid in by sellers',
        'manual_journal' => 'Manual journals',
        'payout_requested' => 'Payout requests',
        'payout_disbursed' => 'Payouts to sellers',
        'payout_rejected' => 'Rejected payouts',
        'customer_refund_disbursed' => 'Refunds paid to customers',
        'shipping_reclassified' => 'Shipping corrections',
        'courier_cod_settled' => 'Courier COD remittances',
        'gateway_settled' => 'Gateway transfers to bank',
        'self_delivery_fee_moved' => 'Self-delivery fees',
        'commission_earned' => 'Commission earned',
        'reversal' => 'Reversals and corrections',
    ];

    /**
     * ACC-18: cash flow statement (direct method) for the platform's cash and bank accounts. Each journal that
     * changes cash is grouped by its business event. A journal whose other side is owner equity is a financing
     * flow; everything else is operating (the platform has no investing activity recorded in its chart). Moving
     * money between cash and bank nets to zero and is left out.
     *
     * @return array{
     *     start_date: ?string,
     *     end_date: ?string,
     *     period_label: string,
     *     opening_cash: float,
     *     operating: array{lines: list<array{event: string, label: string, inflows: float, outflows: float, net: float}>, inflows: float, outflows: float, net: float},
     *     financing: array{lines: list<array{event: string, label: string, inflows: float, outflows: float, net: float}>, inflows: float, outflows: float, net: float},
     *     net_change: float,
     *     closing_cash: float,
     *     is_consistent: bool
     * }
     */
    public function getCashFlowStatement(?string $startDate = null, ?string $endDate = null): array
    {
        ['is_all_time' => $isAllTime, 'start' => $start, 'end' => $end, 'label' => $periodLabel] = $this->resolvePeriod($startDate, $endDate);

        $cashAccountIds = AccountingAccount::query()->whereIn('system_slug', self::CASH_ACCOUNT_SLUGS)->pluck('id')->all();
        $equityAccountIds = AccountingAccount::query()->where('type', AccountType::EQUITY)->pluck('id')->all();
        $statuses = [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value];

        $cashBalancePoisha = function (?string $before, ?string $through) use ($cashAccountIds, $statuses): int {
            $row = DB::table('accounting_journal_lines')
                ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
                ->whereIn('accounting_journal_entries.status', $statuses)
                ->whereIn('accounting_journal_lines.account_id', $cashAccountIds === [] ? [0] : $cashAccountIds)
                ->when($before !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '<', $before))
                ->when($through !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '<=', $through))
                ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit')
                ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit')
                ->first();

            return (int) round(((float) $row->total_debit - (float) $row->total_credit) * 100);
        };

        $openingPoisha = (! $isAllTime && $start !== null) ? $cashBalancePoisha($start, null) : 0;
        $closingPoisha = $cashBalancePoisha(null, $isAllTime ? null : $end);

        $journals = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', $statuses)
            ->when(! $isAllTime && $start !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '>=', $start))
            ->when(! $isAllTime && $end !== null, fn ($q) => $q->whereDate('accounting_journal_entries.entry_date', '<=', $end))
            ->select('accounting_journal_entries.id', 'accounting_journal_entries.financial_event', 'accounting_journal_lines.account_id', 'accounting_journal_lines.debit', 'accounting_journal_lines.credit')
            ->get()
            ->groupBy('id');

        $sections = ['operating' => [], 'financing' => []];

        foreach ($journals as $lines) {
            $cashNetPoisha = 0;
            $touchesEquity = false;

            foreach ($lines as $line) {
                if (in_array((int) $line->account_id, $cashAccountIds, true)) {
                    $cashNetPoisha += (int) round(((float) $line->debit - (float) $line->credit) * 100);
                } elseif (in_array((int) $line->account_id, $equityAccountIds, true)) {
                    $touchesEquity = true;
                }
            }

            if ($cashNetPoisha === 0) {
                continue;
            }

            $section = $touchesEquity ? 'financing' : 'operating';
            $event = (string) $lines->first()->financial_event;
            $sections[$section][$event] ??= ['in' => 0, 'out' => 0];
            $sections[$section][$event][$cashNetPoisha > 0 ? 'in' : 'out'] += abs($cashNetPoisha);
        }

        $build = function (array $events): array {
            ksort($events);
            $lines = [];
            $in = 0;
            $out = 0;

            foreach ($events as $event => $amounts) {
                $lines[] = [
                    'event' => (string) $event,
                    'label' => self::CASH_EVENT_LABELS[$event] ?? Str::headline((string) $event),
                    'inflows' => $this->normalizeZero($amounts['in'] / 100),
                    'outflows' => $this->normalizeZero($amounts['out'] / 100),
                    'net' => $this->normalizeZero(($amounts['in'] - $amounts['out']) / 100),
                ];
                $in += $amounts['in'];
                $out += $amounts['out'];
            }

            return [
                'lines' => $lines,
                'inflows' => $this->normalizeZero($in / 100),
                'outflows' => $this->normalizeZero($out / 100),
                'net' => $this->normalizeZero(($in - $out) / 100),
                'net_poisha' => $in - $out,
            ];
        };

        $operating = $build($sections['operating']);
        $financing = $build($sections['financing']);
        $netChangePoisha = $operating['net_poisha'] + $financing['net_poisha'];
        unset($operating['net_poisha'], $financing['net_poisha']);

        return [
            'start_date' => $start,
            'end_date' => $end,
            'period_label' => $periodLabel,
            'opening_cash' => $this->normalizeZero($openingPoisha / 100),
            'operating' => $operating,
            'financing' => $financing,
            'net_change' => $this->normalizeZero($netChangePoisha / 100),
            'closing_cash' => $this->normalizeZero($closingPoisha / 100),
            'is_consistent' => $openingPoisha + $netChangePoisha === $closingPoisha,
        ];
    }

    /**
     * Turns the report's date inputs into a period. No dates means the current Dhaka month; 'all' means all time.
     *
     * @return array{is_all_time: bool, start: ?string, end: ?string, label: string}
     */
    private function resolvePeriod(?string $startDate, ?string $endDate): array
    {
        $isAllTime = ($startDate === 'all' || $startDate === 'all-time');

        if (! $isAllTime && empty($startDate) && empty($endDate)) {
            $startDate = now(config('app.display_timezone'))->startOfMonth()->toDateString();
            $endDate = now(config('app.display_timezone'))->endOfMonth()->toDateString();
        }

        $start = null;
        if (! $isAllTime && ! empty($startDate)) {
            try {
                $start = Carbon::parse($startDate)->toDateString();
            } catch (\Throwable) {
                $start = null;
            }
        }

        $end = null;
        if (! $isAllTime && ! empty($endDate)) {
            try {
                $end = Carbon::parse($endDate)->toDateString();
            } catch (\Throwable) {
                $end = null;
            }
        }

        if ($start !== null && $end !== null && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        $periodLabel = match (true) {
            $isAllTime => 'All Time',
            $start !== null && $end !== null => Carbon::parse($start)->format('d M Y').' - '.Carbon::parse($end)->format('d M Y'),
            $start !== null => 'From '.Carbon::parse($start)->format('d M Y'),
            $end !== null => 'Up to '.Carbon::parse($end)->format('d M Y'),
            default => 'All Time',
        };

        return ['is_all_time' => $isAllTime, 'start' => $start, 'end' => $end, 'label' => $periodLabel];
    }

    /**
     * ACC-18: one statement line per account. The amount is the account's balance in its own normal direction, so
     * it matches the ledger page. A contra account (its normal balance is the opposite of its section's, such as
     * sales returns under revenue) is marked and shown in brackets, and is deducted from the section total.
     *
     * @param  Collection<int, AccountingAccount>  $accounts
     * @param  Collection<int|string, object>  $totals
     * @return array{rows: array<int, array{code: string, name: string, amount: float, is_contra: bool, formatted_amount: string}>, total: float}
     */
    private function statementRows(Collection $accounts, Collection $totals, AccountType $section): array
    {
        $rows = [];
        $sectionTotalPoisha = 0;

        foreach ($accounts as $account) {
            $line = $totals->get($account->id);
            $debitPoisha = (int) round(((float) ($line->total_debit ?? 0.0)) * 100);
            $creditPoisha = (int) round(((float) ($line->total_credit ?? 0.0)) * 100);

            $normalBalance = $account->normal_balance instanceof NormalBalance
                ? $account->normal_balance
                : (NormalBalance::tryFrom((string) $account->normal_balance) ?? $section->defaultNormalBalance());

            $balancePoisha = $normalBalance === NormalBalance::DEBIT
                ? $debitPoisha - $creditPoisha
                : $creditPoisha - $debitPoisha;
            $isContra = $normalBalance !== $section->defaultNormalBalance();
            $amount = $this->normalizeZero($balancePoisha / 100);

            $rows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'is_contra' => $isContra,
                'formatted_amount' => $isContra ? '('.$this->formatMoney($amount).')' : $this->formatMoney($amount),
            ];

            $sectionTotalPoisha += $isContra ? -$balancePoisha : $balancePoisha;
        }

        return ['rows' => $rows, 'total' => $this->normalizeZero($sectionTotalPoisha / 100)];
    }

    private function normalizeZero(float $value): float
    {
        return $value == 0.0 ? 0.0 : round($value, 2);
    }

    /**
     * Format a monetary amount in BDT currency with 2 decimals.
     */
    public function formatMoney(float $amount, bool $signed = false): string
    {
        $rounded = round($amount, 2);

        if (abs($rounded) < 0.005) {
            return '৳0.00';
        }

        if ($signed) {
            if ($rounded > 0.0) {
                return '+৳'.number_format($rounded, 2);
            }

            return '-৳'.number_format(abs($rounded), 2);
        }

        if ($rounded < 0.0) {
            return '-৳'.number_format(abs($rounded), 2);
        }

        return '৳'.number_format($rounded, 2);
    }
}
