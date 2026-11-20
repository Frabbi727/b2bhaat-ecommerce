<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingPeriod;
use App\Services\Audit\AuditService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class TrialBalancePage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Trial balance';

    protected static ?string $title = 'Trial Balance';

    protected static ?string $slug = 'trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.admin.pages.trial-balance-page';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?int $periodId = null;

    public bool $hideZeroBalances = false;

    public ?string $preset = 'all';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print Statement')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->extraAttributes(['onclick' => 'window.print(); return false;']),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->action('exportCsv'),
        ];
    }

    public function updatedPeriodId(?int $value): void
    {
        if ($value) {
            $period = AccountingPeriod::find($value);
            if ($period) {
                $this->startDate = $period->start_date?->format('Y-m-d');
                $this->endDate = $period->end_date?->format('Y-m-d');
                $this->preset = null;
            }
        }
    }

    public function updatedStartDate(): void
    {
        $this->periodId = null;
        $this->preset = null;
    }

    public function updatedEndDate(): void
    {
        $this->periodId = null;
        $this->preset = null;
    }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
        $this->periodId = null;

        match ($preset) {
            'month' => (function (): void {
                $this->startDate = Carbon::now(config('app.display_timezone'))->startOfMonth()->toDateString();
                $this->endDate = Carbon::now(config('app.display_timezone'))->endOfMonth()->toDateString();
            })(),
            'quarter' => (function (): void {
                $this->startDate = Carbon::now(config('app.display_timezone'))->startOfQuarter()->toDateString();
                $this->endDate = Carbon::now(config('app.display_timezone'))->endOfQuarter()->toDateString();
            })(),
            'year' => (function (): void {
                $this->startDate = Carbon::now(config('app.display_timezone'))->startOfYear()->toDateString();
                $this->endDate = Carbon::now(config('app.display_timezone'))->endOfYear()->toDateString();
            })(),
            default => (function (): void {
                $this->startDate = null;
                $this->endDate = null;
                $this->preset = 'all';
            })(),
        };
    }

    public function resetFilters(): void
    {
        $this->startDate = null;
        $this->endDate = null;
        $this->periodId = null;
        $this->hideZeroBalances = false;
        $this->preset = 'all';
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
     * Export the current trial balance report as a CSV download.
     */
    public function exportCsv(): StreamedResponse
    {
        $data = $this->getTrialBalanceData();
        $dateLabel = $this->endDate ? Carbon::parse($this->endDate)->format('Y-m-d') : 'all-time';
        $filename = "trial-balance-{$dateLabel}.csv";

        \app(AuditService::class)->recordExport('trial balance', $filename, null);

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['B2Bhaat Marketplace — General Ledger Trial Balance']);
            // ACC-16: with a start date this is the movement for the range, not balances as of a date
            fputcsv($handle, $this->startDate
                ? ['Period:', $this->startDate.' to '.($this->endDate ?? 'today'), 'Generated At:', Carbon::now(config('app.display_timezone'))->toDateTimeString()]
                : ['As of Date:', $this->endDate ?? 'All Time', 'Generated At:', Carbon::now(config('app.display_timezone'))->toDateTimeString()]);
            fputcsv($handle, ['Currency:', 'Bangladeshi Taka (BDT / ৳)']);
            fputcsv($handle, []);

            fputcsv($handle, ['Account Code', 'Account Name', 'Category', 'System Slug', 'Total Debit (BDT)', 'Total Credit (BDT)', 'Net Debit (BDT)', 'Net Credit (BDT)']);

            foreach ($data['categories'] as $category) {
                foreach ($category['accounts'] as $account) {
                    fputcsv($handle, [
                        $account['code'],
                        $account['name'],
                        $category['name'],
                        $account['system_slug'] ?? '',
                        number_format($account['total_debit'], 2, '.', ''),
                        number_format($account['total_credit'], 2, '.', ''),
                        number_format($account['net_debit'], 2, '.', ''),
                        number_format($account['net_credit'], 2, '.', ''),
                    ]);
                }

                // Category subtotal
                fputcsv($handle, [
                    '',
                    'SUBTOTAL: '.$category['name'],
                    $category['name'],
                    '',
                    number_format($category['total_debit'], 2, '.', ''),
                    number_format($category['total_credit'], 2, '.', ''),
                    number_format($category['total_net_debit'], 2, '.', ''),
                    number_format($category['total_net_credit'], 2, '.', ''),
                ]);
                fputcsv($handle, []);
            }

            // Grand Total
            fputcsv($handle, [
                '',
                'GRAND TOTAL',
                '',
                '',
                number_format($data['grand_total']['total_debit'], 2, '.', ''),
                number_format($data['grand_total']['total_credit'], 2, '.', ''),
                number_format($data['grand_total']['total_net_debit'], 2, '.', ''),
                number_format($data['grand_total']['total_net_credit'], 2, '.', ''),
            ]);

            fputcsv($handle, ['Equilibrium Status:', $data['grand_total']['is_balanced'] ? 'BALANCED' : 'OUT OF BALANCE']);
            if ($data['unbalanced_journals'] !== []) {
                fputcsv($handle, ['Journals that do not balance:', implode(', ', $data['unbalanced_journals'])]);
            }
            fputcsv($handle, ['Discrepancy Variance:', number_format(abs($data['grand_total']['difference']), 2, '.', '')]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
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
            $linesQuery->where('accounting_journal_entries.entry_date', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $linesQuery->where('accounting_journal_entries.entry_date', '<=', $this->endDate);
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
                'name' => 'Assets',
                'display_name' => '1. Assets',
                'code_range' => '1000 - 1999',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::LIABILITY->value => [
                'name' => 'Liabilities',
                'display_name' => '2. Liabilities',
                'code_range' => '2000 - 2999',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::EQUITY->value => [
                'name' => 'Equity',
                'display_name' => '3. Equity',
                'code_range' => '3000 - 3999',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::REVENUE->value => [
                'name' => 'Revenue',
                'display_name' => '4. Revenue',
                'code_range' => '4000 - 4999',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
            AccountType::EXPENSE->value => [
                'name' => 'Expenses',
                'display_name' => '5. Expenses',
                'code_range' => '5000 - 5999',
                'accounts' => [],
                'total_debit' => 0.0,
                'total_credit' => 0.0,
                'total_net_debit' => 0.0,
                'total_net_credit' => 0.0,
            ],
        ];

        $grandDebit = 0.0;
        $grandCredit = 0.0;
        $grandNetDebit = 0.0;
        $grandNetCredit = 0.0;

        foreach ($accounts as $account) {
            $type = $account->type instanceof AccountType ? $account->type->value : (string) $account->type;
            if (! isset($categories[$type])) {
                continue;
            }

            $line = $totals->get($account->id);
            $totalDebit = round((float) ($line->total_debit ?? 0.0), 2);
            $totalCredit = round((float) ($line->total_credit ?? 0.0), 2);

            $netDebit = $totalDebit > $totalCredit ? round($totalDebit - $totalCredit, 2) : 0.00;
            $netCredit = $totalCredit > $totalDebit ? round($totalCredit - $totalDebit, 2) : 0.00;

            if ($this->hideZeroBalances && abs($netDebit) < 0.001 && abs($netCredit) < 0.001 && abs($totalDebit) < 0.001 && abs($totalCredit) < 0.001) {
                continue;
            }

            $categories[$type]['accounts'][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'system_slug' => $account->system_slug,
                'is_system' => $account->is_system,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_debit' => $netDebit,
                'net_credit' => $netCredit,
            ];

            $categories[$type]['total_debit'] = round($categories[$type]['total_debit'] + $totalDebit, 2);
            $categories[$type]['total_credit'] = round($categories[$type]['total_credit'] + $totalCredit, 2);
            $categories[$type]['total_net_debit'] = round($categories[$type]['total_net_debit'] + $netDebit, 2);
            $categories[$type]['total_net_credit'] = round($categories[$type]['total_net_credit'] + $netCredit, 2);

            $grandDebit = round($grandDebit + $totalDebit, 2);
            $grandCredit = round($grandCredit + $totalCredit, 2);
            $grandNetDebit = round($grandNetDebit + $netDebit, 2);
            $grandNetCredit = round($grandNetCredit + $netCredit, 2);
        }

        $difference = round($grandDebit - $grandCredit, 2);
        $unbalancedJournals = $this->unbalancedJournals();
        // ACC-16: equal grand totals alone prove little (posting forces each journal to balance), so every journal
        // in range is also checked on its own
        $isBalanced = abs($difference) < 0.001 && $unbalancedJournals === [];

        $grandTotal = [
            'total_debit' => $grandDebit,
            'total_credit' => $grandCredit,
            'total_net_debit' => $grandNetDebit,
            'total_net_credit' => $grandNetCredit,
            'difference' => $difference,
            'is_balanced' => $isBalanced,
            'unbalanced_journals' => $unbalancedJournals,
        ];

        return [
            'categories' => $categories,
            'grand_total' => $grandTotal,
            'total_debit' => $grandDebit,
            'total_credit' => $grandCredit,
            'total_net_debit' => $grandNetDebit,
            'total_net_credit' => $grandNetCredit,
            'difference' => $difference,
            'is_balanced' => $isBalanced,
            'unbalanced_journals' => $unbalancedJournals,
        ];
    }

    /**
     * Journal numbers in the selected range whose own lines don't add up (only possible through a direct database
     * edit or import, since posting refuses it).
     *
     * @return list<string>
     */
    private function unbalancedJournals(): array
    {
        $query = DB::table('accounting_journal_entries')
            ->join('accounting_journal_lines', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->whereIn('accounting_journal_entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value]);

        if ($this->periodId) {
            $query->where('accounting_journal_entries.period_id', $this->periodId);
        }
        if ($this->startDate) {
            $query->where('accounting_journal_entries.entry_date', '>=', $this->startDate);
        }
        if ($this->endDate) {
            $query->where('accounting_journal_entries.entry_date', '<=', $this->endDate);
        }

        return $query->groupBy('accounting_journal_entries.id', 'accounting_journal_entries.journal_number')
            ->havingRaw('ROUND(SUM(accounting_journal_lines.debit) - SUM(accounting_journal_lines.credit), 2) <> 0')
            ->orderBy('accounting_journal_entries.journal_number')
            ->pluck('accounting_journal_entries.journal_number')
            ->all();
    }
}
