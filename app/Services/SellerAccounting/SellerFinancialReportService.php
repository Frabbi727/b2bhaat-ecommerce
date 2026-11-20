<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerWallet;
use App\Models\WalletTransaction;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seller books: the statements a seller reads — profit & loss, balance sheet, trial balance, general ledger and
 * profit per product. Every figure comes from the seller's own journal lines; dates are entry dates (inclusive).
 */
class SellerFinancialReportService
{
    /** General ledger pages show at most this many lines. */
    public const LEDGER_LINE_LIMIT = 1000;

    public function __construct(
        private readonly Chart $chart,
    ) {}

    /**
     * @return array{
     *     income: list<array{code: string, name: string, amount: float}>,
     *     net_sales: float,
     *     cost_of_goods_sold: float,
     *     gross_profit: float,
     *     gross_margin: float,
     *     expenses: list<array{code: string, name: string, amount: float}>,
     *     total_expenses: float,
     *     net_profit: float,
     *     net_margin: float,
     *     is_profit: bool
     * }
     */
    public function profitAndLoss(int $sellerId, ?string $from, ?string $to): array
    {
        $rows = $this->accountBalances($sellerId, $from, $to);

        $income = [];
        $netSales = 0.0;
        $expenses = [];
        $totalExpenses = 0.0;
        $cogs = 0.0;

        foreach ($rows as $row) {
            /** @var SellerAccount $account */
            $account = $row['account'];

            if ($account->type === AccountType::REVENUE) {
                $amount = round($row['credit'] - $row['debit'], 2);
                if ($amount != 0.0) {
                    $income[] = ['code' => $account->code, 'name' => $account->name, 'amount' => $amount];
                    $netSales += $amount;
                }
            } elseif ($account->type === AccountType::EXPENSE) {
                $amount = round($row['debit'] - $row['credit'], 2);
                if ($account->slug === Chart::COGS) {
                    $cogs += $amount;
                } elseif ($amount != 0.0) {
                    $expenses[] = ['code' => $account->code, 'name' => $account->name, 'amount' => $amount];
                    $totalExpenses += $amount;
                }
            }
        }

        $netSales = round($netSales, 2);
        $grossProfit = round($netSales - $cogs, 2);
        $netProfit = round($grossProfit - $totalExpenses, 2);

        return [
            'income' => $income,
            'net_sales' => $netSales,
            'cost_of_goods_sold' => round($cogs, 2),
            'gross_profit' => $grossProfit,
            'gross_margin' => $this->percent($grossProfit, $netSales),
            'expenses' => $expenses,
            'total_expenses' => round($totalExpenses, 2),
            'net_profit' => $netProfit,
            'net_margin' => $this->percent($netProfit, $netSales),
            'is_profit' => $netProfit >= 0,
        ];
    }

    /**
     * Assets = liabilities + equity as at a date. Profit to date is shown inside equity.
     *
     * @return array{
     *     assets: list<array{code: string, name: string, amount: float}>,
     *     total_assets: float,
     *     liabilities: list<array{code: string, name: string, amount: float}>,
     *     total_liabilities: float,
     *     equity: list<array{code: string, name: string, amount: float}>,
     *     profit_to_date: float,
     *     total_equity: float,
     *     is_balanced: bool
     * }
     */
    public function balanceSheet(int $sellerId, ?string $asOf): array
    {
        $sections = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $totals = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0];
        $profit = 0.0;

        foreach ($this->accountBalances($sellerId, null, $asOf) as $row) {
            /** @var SellerAccount $account */
            $account = $row['account'];
            $debitBalance = round($row['debit'] - $row['credit'], 2);

            $section = match ($account->type) {
                AccountType::ASSET => 'assets',
                AccountType::LIABILITY => 'liabilities',
                AccountType::EQUITY => 'equity',
                default => null,
            };

            if ($section === null) {
                $profit -= $debitBalance;

                continue;
            }

            $amount = $section === 'assets' ? $debitBalance : -$debitBalance;
            if ($amount != 0.0) {
                $sections[$section][] = ['code' => $account->code, 'name' => $account->name, 'amount' => $amount];
                $totals[$section] += $amount;
            }
        }

        $profit = round($profit, 2);
        $totalEquity = round($totals['equity'] + $profit, 2);

        return [
            'assets' => $sections['assets'],
            'total_assets' => round($totals['assets'], 2),
            'liabilities' => $sections['liabilities'],
            'total_liabilities' => round($totals['liabilities'], 2),
            'equity' => $sections['equity'],
            'profit_to_date' => $profit,
            'total_equity' => $totalEquity,
            'is_balanced' => abs(round($totals['assets'] - $totals['liabilities'] - $totalEquity, 2)) < 0.005,
        ];
    }

    /**
     * @return array{
     *     rows: list<array{code: string, name: string, type: string, debit: float, credit: float}>,
     *     total_debit: float,
     *     total_credit: float,
     *     is_balanced: bool
     * }
     */
    public function trialBalance(int $sellerId, ?string $asOf): array
    {
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($this->accountBalances($sellerId, null, $asOf) as $row) {
            /** @var SellerAccount $account */
            $account = $row['account'];
            $balance = round($row['debit'] - $row['credit'], 2);
            if ($balance == 0.0) {
                continue;
            }

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => ucfirst($account->type->value),
                'debit' => $balance > 0 ? $balance : 0.0,
                'credit' => $balance < 0 ? -$balance : 0.0,
            ];
            $totalDebit += max(0.0, $balance);
            $totalCredit += max(0.0, -$balance);
        }

        return [
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced' => abs(round($totalDebit - $totalCredit, 2)) < 0.005,
        ];
    }

    /**
     * One account's movements with a running balance (shown on the account's normal side).
     *
     * @return array{
     *     account: SellerAccount,
     *     opening_balance: float,
     *     lines: list<array{date: string, entry_number: string, description: string, narration: ?string, debit: float, credit: float, balance: float}>,
     *     total_debit: float,
     *     total_credit: float,
     *     closing_balance: float,
     *     is_truncated: bool
     * }
     */
    public function generalLedger(int $sellerId, int $accountId, ?string $from, ?string $to): array
    {
        $account = SellerAccount::query()->where('seller_id', $sellerId)->findOrFail($accountId);
        $sign = $account->isDebitNormal() ? 1 : -1;

        $opening = 0.0;
        if ($from !== null) {
            $before = $this->lines($sellerId, null, null)
                ->where('l.seller_account_id', $account->id)
                ->where('e.entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
                ->first();
            $opening = round($sign * ((float) $before->debit - (float) $before->credit), 2);
        }

        $rows = $this->lines($sellerId, $from, $to)
            ->where('l.seller_account_id', $account->id)
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.id')
            ->limit(self::LEDGER_LINE_LIMIT + 1)
            ->get(['e.entry_date', 'e.entry_number', 'e.description', 'l.narration', 'l.debit', 'l.credit']);

        $truncated = $rows->count() > self::LEDGER_LINE_LIMIT;
        $balance = $opening;
        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows->take(self::LEDGER_LINE_LIMIT) as $row) {
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;
            $balance = round($balance + $sign * ($debit - $credit), 2);
            $totalDebit += $debit;
            $totalCredit += $credit;

            $lines[] = [
                'date' => (string) $row->entry_date,
                'entry_number' => (string) $row->entry_number,
                'description' => (string) $row->description,
                'narration' => $row->narration,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];
        }

        return [
            'account' => $account,
            'opening_balance' => $opening,
            'lines' => $lines,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'closing_balance' => $balance,
            'is_truncated' => $truncated,
        ];
    }

    /**
     * Profit per product: sales less returns, cost of goods and B2Bhaat commission.
     *
     * @return list<array{product_id: int, title: string, sales: float, returns: float, net_sales: float, cost: float, gross_profit: float, commission: float, profit: float, margin: float}>
     */
    public function productProfit(int $sellerId, ?string $from, ?string $to): array
    {
        $rows = $this->lines($sellerId, $from, $to)
            ->join('seller_accounts as a', 'a.id', '=', 'l.seller_account_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->whereNotNull('l.product_id')
            ->whereIn('a.slug', [Chart::SALES, Chart::SALES_RETURNS, Chart::COGS, Chart::COMMISSION])
            ->groupBy('l.product_id', 'p.title', 'a.slug')
            ->get(['l.product_id', 'p.title', 'a.slug', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit')]);

        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            $products[$id] ??= ['product_id' => $id, 'title' => (string) ($row->title ?? "Product #{$id}"), 'sales' => 0.0, 'returns' => 0.0, 'cost' => 0.0, 'commission' => 0.0];
            $debitBalance = (float) $row->debit - (float) $row->credit;

            match ($row->slug) {
                Chart::SALES => $products[$id]['sales'] -= $debitBalance,
                Chart::SALES_RETURNS => $products[$id]['returns'] += $debitBalance,
                Chart::COGS => $products[$id]['cost'] += $debitBalance,
                Chart::COMMISSION => $products[$id]['commission'] += $debitBalance,
                default => null,
            };
        }

        $result = array_map(function (array $product): array {
            $netSales = round($product['sales'] - $product['returns'], 2);
            $gross = round($netSales - $product['cost'], 2);
            $profit = round($gross - $product['commission'], 2);

            return [
                'product_id' => $product['product_id'],
                'title' => $product['title'],
                'sales' => round($product['sales'], 2),
                'returns' => round($product['returns'], 2),
                'net_sales' => $netSales,
                'cost' => round($product['cost'], 2),
                'gross_profit' => $gross,
                'commission' => round($product['commission'], 2),
                'profit' => $profit,
                'margin' => $this->percent($profit, $netSales),
            ];
        }, array_values($products));

        usort($result, fn (array $a, array $b): int => $b['profit'] <=> $a['profit']);

        return $result;
    }

    /**
     * Headline figures for the accounts overview.
     *
     * @return array{cash_and_bank: float, receivable_from_b2bhaat: float, inventory: float, capital_invested: float, withdrawn: float, supplier_dues: float, net_worth: float}
     */
    public function position(int $sellerId): array
    {
        $moneyIds = array_keys($this->chart->moneyAccountOptions($sellerId));
        $bySlug = [];
        $cashAndBank = 0.0;
        foreach ($this->accountBalances($sellerId, null, null) as $accountId => $row) {
            $bySlug[$row['account']->slug] = round($row['debit'] - $row['credit'], 2);
            if (in_array($accountId, $moneyIds, true)) {
                $cashAndBank += $row['debit'] - $row['credit'];
            }
        }

        $sheet = $this->balanceSheet($sellerId, null);

        return [
            'cash_and_bank' => round($cashAndBank, 2),
            'receivable_from_b2bhaat' => round(($bySlug[Chart::B2BHAAT_ON_HOLD] ?? 0) + ($bySlug[Chart::B2BHAAT_AVAILABLE] ?? 0) + ($bySlug[Chart::PAYOUT_IN_TRANSIT] ?? 0), 2),
            'inventory' => $bySlug[Chart::INVENTORY] ?? 0.0,
            'capital_invested' => round(-($bySlug[Chart::CAPITAL] ?? 0) - ($bySlug[Chart::OPENING_BALANCE] ?? 0), 2),
            'withdrawn' => $bySlug[Chart::DRAWINGS] ?? 0.0,
            'supplier_dues' => round(-($bySlug[Chart::ACCOUNTS_PAYABLE] ?? 0), 2),
            'net_worth' => $sheet['total_equity'],
        ];
    }

    /**
     * Do the B2Bhaat accounts in the seller's books agree with the wallet?
     *
     * @return array{on_hold_books: float, on_hold_wallet: float, available_books: float, available_wallet: float, in_transit_books: float, in_transit_wallet: float, is_consistent: bool}
     */
    public function walletReconciliation(int $sellerId): array
    {
        $bySlug = [];
        foreach ($this->accountBalances($sellerId, null, null) as $row) {
            $bySlug[$row['account']->slug] = round($row['debit'] - $row['credit'], 2);
        }

        $wallet = SellerWallet::query()->where('seller_id', $sellerId)->first();
        $pending = $wallet
            ? (float) WalletTransaction::query()
                ->where('seller_wallet_id', $wallet->id)
                ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
                ->where('status', WalletTransaction::STATUS_REQUESTED)
                ->sum('amount')
            : 0.0;

        $result = [
            'on_hold_books' => $bySlug[Chart::B2BHAAT_ON_HOLD] ?? 0.0,
            'on_hold_wallet' => round((float) ($wallet?->escrow_held_balance ?? 0), 2),
            'available_books' => $bySlug[Chart::B2BHAAT_AVAILABLE] ?? 0.0,
            'available_wallet' => round((float) ($wallet?->available_balance ?? 0), 2),
            'in_transit_books' => $bySlug[Chart::PAYOUT_IN_TRANSIT] ?? 0.0,
            'in_transit_wallet' => round($pending, 2),
        ];

        $result['is_consistent'] = abs($result['on_hold_books'] - $result['on_hold_wallet']) < 0.005
            && abs($result['available_books'] - $result['available_wallet']) < 0.005
            && abs($result['in_transit_books'] - $result['in_transit_wallet']) < 0.005;

        return $result;
    }

    /**
     * Debit and credit totals per account (every account, even those with no lines), keyed by account id.
     *
     * @return array<int, array{account: SellerAccount, debit: float, credit: float}>
     */
    public function accountBalances(int $sellerId, ?string $from, ?string $to): array
    {
        $sums = $this->lines($sellerId, $from, $to)
            ->groupBy('l.seller_account_id')
            ->get(['l.seller_account_id', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit')])
            ->keyBy('seller_account_id');

        $result = [];
        foreach ($this->chart->accounts($sellerId) as $account) {
            $sum = $sums->get($account->id);
            $result[$account->id] = [
                'account' => $account,
                'debit' => round((float) ($sum->debit ?? 0), 2),
                'credit' => round((float) ($sum->credit ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * Daily net profit for a chart: income less all costs, per entry date.
     *
     * @return Collection<string, float> profit by Y-m-d
     */
    public function dailyProfit(int $sellerId, string $from, string $to): Collection
    {
        return $this->lines($sellerId, $from, $to)
            ->join('seller_accounts as a', 'a.id', '=', 'l.seller_account_id')
            ->whereIn('a.type', [AccountType::REVENUE->value, AccountType::EXPENSE->value])
            ->groupBy('e.entry_date')
            ->orderBy('e.entry_date')
            ->get(['e.entry_date', DB::raw('SUM(l.credit) - SUM(l.debit) as profit')])
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->entry_date, 0, 10) => round((float) $row->profit, 2)]);
    }

    /**
     * @return Builder
     */
    private function lines(int $sellerId, ?string $from, ?string $to)
    {
        return DB::table('seller_journal_lines as l')
            ->join('seller_journal_entries as e', 'e.id', '=', 'l.seller_journal_entry_id')
            ->where('l.seller_id', $sellerId)
            ->when($from !== null, fn ($query) => $query->where('e.entry_date', '>=', $from))
            // "Before the next day" includes the whole end date however the driver stores a date
            ->when($to !== null, fn ($query) => $query->where('e.entry_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString()));
    }

    private function percent(float $part, float $whole): float
    {
        return $whole != 0.0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
