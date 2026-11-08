<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     expenses: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
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
        $start = ! empty($startDate)
            ? Carbon::parse($startDate)->toDateString()
            : now()->startOfMonth()->toDateString();

        $end = ! empty($endDate)
            ? Carbon::parse($endDate)->toDateString()
            : now()->endOfMonth()->toDateString();

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $periodLabel = Carbon::parse($start)->format('M d, Y').' - '.Carbon::parse($end)->format('M d, Y');

        /** @var Collection<int, object{account_id: int, total_debit: string, total_credit: string}> $totals */
        $totals = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ])
            ->whereDate('accounting_journal_entries.entry_date', '>=', $start)
            ->whereDate('accounting_journal_entries.entry_date', '<=', $end)
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

        $revenueRows = [];
        $totalRevenues = 0.0;

        foreach ($revenueAccounts as $account) {
            $line = $totals->get($account->id);
            $debit = (float) ($line->total_debit ?? 0.0);
            $credit = (float) ($line->total_credit ?? 0.0);
            $amount = round($credit - $debit, 2);

            $revenueRows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'formatted_amount' => $this->formatMoney($amount),
            ];

            $totalRevenues = round($totalRevenues + $amount, 2);
        }

        // Operating Expenses (5000 series): Debit - Credit
        $expenseAccounts = AccountingAccount::query()
            ->where('type', AccountType::EXPENSE)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        $expenseRows = [];
        $totalExpenses = 0.0;

        foreach ($expenseAccounts as $account) {
            $line = $totals->get($account->id);
            $debit = (float) ($line->total_debit ?? 0.0);
            $credit = (float) ($line->total_credit ?? 0.0);
            $amount = round($debit - $credit, 2);

            $expenseRows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'formatted_amount' => $this->formatMoney($amount),
            ];

            $totalExpenses = round($totalExpenses + $amount, 2);
        }

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
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     liabilities: array{
     *         accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
     *         total: float,
     *         formatted_total: string
     *     },
     *     equity: array{
     *         capital_accounts: array<int, array{code: string, name: string, amount: float, formatted_amount: string}>,
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
        $asOf = ! empty($asOfDate)
            ? Carbon::parse($asOfDate)->toDateString()
            : now()->toDateString();

        $formattedAsOfDate = Carbon::parse($asOf)->format('M d, Y');

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

        $assetRows = [];
        $totalAssets = 0.0;

        foreach ($assetAccounts as $account) {
            $line = $totals->get($account->id);
            $debit = (float) ($line->total_debit ?? 0.0);
            $credit = (float) ($line->total_credit ?? 0.0);
            $amount = round($debit - $credit, 2);

            $assetRows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'formatted_amount' => $this->formatMoney($amount),
            ];

            $totalAssets = round($totalAssets + $amount, 2);
        }

        // Liabilities (2000 series): Credit - Debit
        $liabilityAccounts = AccountingAccount::query()
            ->where('type', AccountType::LIABILITY)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        $liabilityRows = [];
        $totalLiabilities = 0.0;

        foreach ($liabilityAccounts as $account) {
            $line = $totals->get($account->id);
            $debit = (float) ($line->total_debit ?? 0.0);
            $credit = (float) ($line->total_credit ?? 0.0);
            $amount = round($credit - $debit, 2);

            $liabilityRows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'formatted_amount' => $this->formatMoney($amount),
            ];

            $totalLiabilities = round($totalLiabilities + $amount, 2);
        }

        // Equity (3000 series): Credit - Debit
        $equityAccounts = AccountingAccount::query()
            ->where('type', AccountType::EQUITY)
            ->where(function ($query) use ($totals): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('code')
            ->get();

        $capitalRows = [];
        $retainedEarnings = 0.0;

        foreach ($equityAccounts as $account) {
            $line = $totals->get($account->id);
            $debit = (float) ($line->total_debit ?? 0.0);
            $credit = (float) ($line->total_credit ?? 0.0);
            $amount = round($credit - $debit, 2);

            $capitalRows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
                'formatted_amount' => $this->formatMoney($amount),
            ];

            $retainedEarnings = round($retainedEarnings + $amount, 2);
        }

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
