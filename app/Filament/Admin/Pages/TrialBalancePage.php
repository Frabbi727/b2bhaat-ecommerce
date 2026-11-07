<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class TrialBalancePage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Trial Balance';

    protected static ?string $title = 'Trial Balance';

    protected static ?string $slug = 'trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.admin.pages.trial-balance-page';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?int $periodId = null;

    public bool $hideZeroBalances = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function resetFilters(): void
    {
        $this->startDate = null;
        $this->endDate = null;
        $this->periodId = null;
        $this->hideZeroBalances = false;
    }

    public function applyFilters(): void
    {
        // Re-renders the trial balance table with current filter properties.
    }

    /**
     * @return Collection<int, AccountingPeriod>
     */
    public function getPeriods(): Collection
    {
        return AccountingPeriod::with('fiscalYear')
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * Compile trial balance report data for active filters.
     *
     * @return array{
     *     categories: array<string, array<string, mixed>>,
     *     grand_total: array{
     *         total_debit: float,
     *         total_credit: float,
     *         total_net_debit: float,
     *         total_net_credit: float,
     *         difference: float,
     *         is_balanced: bool
     *     },
     *     total_debit: float,
     *     total_credit: float,
     *     total_net_debit: float,
     *     total_net_credit: float,
     *     difference: float,
     *     is_balanced: bool
     * }
     */
    public function getTrialBalanceData(): array
    {
        $accounts = AccountingAccount::query()
            ->orderBy('code')
            ->get();

        $linesQuery = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [
                JournalEntryStatus::POSTED->value,
                JournalEntryStatus::REVERSED->value,
            ]);

        if ($this->periodId) {
            $linesQuery->where('accounting_journal_entries.period_id', $this->periodId);
        }

        if ($this->startDate) {
            $linesQuery->whereDate('accounting_journal_entries.entry_date', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $linesQuery->whereDate('accounting_journal_entries.entry_date', '<=', $this->endDate);
        }

        $totals = $linesQuery
            ->select('accounting_journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) as total_credit')
            ->groupBy('accounting_journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $categories = [
            AccountType::ASSET->value => [
                'key' => AccountType::ASSET->value,
                'name' => 'Assets',
                'label' => 'Assets',
                'display_name' => '1. Assets',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::LIABILITY->value => [
                'key' => AccountType::LIABILITY->value,
                'name' => 'Liabilities',
                'label' => 'Liabilities',
                'display_name' => '2. Liabilities',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::EQUITY->value => [
                'key' => AccountType::EQUITY->value,
                'name' => 'Equity',
                'label' => 'Equity',
                'display_name' => '3. Equity',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::REVENUE->value => [
                'key' => AccountType::REVENUE->value,
                'name' => 'Revenue',
                'label' => 'Revenue',
                'display_name' => '4. Revenue',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::EXPENSE->value => [
                'key' => AccountType::EXPENSE->value,
                'name' => 'Expenses',
                'label' => 'Expenses',
                'display_name' => '5. Expenses',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
        ];

        foreach ($accounts as $account) {
            $accountTotal = $totals->get($account->id);
            $totalDebit = round((float) ($accountTotal->total_debit ?? 0.0), 2);
            $totalCredit = round((float) ($accountTotal->total_credit ?? 0.0), 2);

            $netDebit = $totalDebit > $totalCredit ? round($totalDebit - $totalCredit, 2) : 0.0;
            $netCredit = $totalCredit > $totalDebit ? round($totalCredit - $totalDebit, 2) : 0.0;

            if ($this->hideZeroBalances && abs($netDebit) < 0.001 && abs($netCredit) < 0.001 && abs($totalDebit) < 0.001 && abs($totalCredit) < 0.001) {
                continue;
            }

            $typeKey = $account->type instanceof AccountType ? $account->type->value : (string) $account->type;

            if (! isset($categories[$typeKey])) {
                $categories[$typeKey] = [
                    'key' => $typeKey,
                    'name' => ucfirst($typeKey),
                    'label' => ucfirst($typeKey),
                    'display_name' => ucfirst($typeKey),
                    'accounts' => [],
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                    'total_net_debit' => 0.0,
                    'total_net_credit' => 0.0,
                ];
            }

            $categories[$typeKey]['accounts'][] = [
                'id' => $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'system_slug' => $account->system_slug ? (string) $account->system_slug : null,
                'type' => $typeKey,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_debit' => $netDebit,
                'net_credit' => $netCredit,
            ];

            $categories[$typeKey]['total_debit'] = round($categories[$typeKey]['total_debit'] + $totalDebit, 2);
            $categories[$typeKey]['total_credit'] = round($categories[$typeKey]['total_credit'] + $totalCredit, 2);
            $categories[$typeKey]['total_net_debit'] = round($categories[$typeKey]['total_net_debit'] + $netDebit, 2);
            $categories[$typeKey]['total_net_credit'] = round($categories[$typeKey]['total_net_credit'] + $netCredit, 2);
        }

        $grandTotalDebit = 0.0;
        $grandTotalCredit = 0.0;
        $grandTotalNetDebit = 0.0;
        $grandTotalNetCredit = 0.0;

        foreach ($categories as $cat) {
            $grandTotalDebit = round($grandTotalDebit + $cat['total_debit'], 2);
            $grandTotalCredit = round($grandTotalCredit + $cat['total_credit'], 2);
            $grandTotalNetDebit = round($grandTotalNetDebit + $cat['total_net_debit'], 2);
            $grandTotalNetCredit = round($grandTotalNetCredit + $cat['total_net_credit'], 2);
        }

        $difference = round($grandTotalDebit - $grandTotalCredit, 2);
        $diffClean = abs($difference) < 0.001 ? 0.0 : $difference;
        $isBalanced = abs($diffClean) < 0.001;

        $grandTotalData = [
            'total_debit' => $grandTotalDebit,
            'total_credit' => $grandTotalCredit,
            'total_net_debit' => $grandTotalNetDebit,
            'total_net_credit' => $grandTotalNetCredit,
            'difference' => $diffClean,
            'is_balanced' => $isBalanced,
        ];

        return [
            'categories' => $categories,
            'grand_total' => $grandTotalData,
            'total_debit' => $grandTotalDebit,
            'total_credit' => $grandTotalCredit,
            'total_net_debit' => $grandTotalNetDebit,
            'total_net_credit' => $grandTotalNetCredit,
            'difference' => $diffClean,
            'is_balanced' => $isBalanced,
        ];
    }
}
