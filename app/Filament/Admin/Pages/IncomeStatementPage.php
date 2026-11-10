<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\FinancialStatementService;
use App\Services\Audit\AuditService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class IncomeStatementPage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Income Statement (P&L)';

    protected static ?string $title = 'Income Statement (Profit & Loss)';

    protected static ?string $slug = 'income-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected string $view = 'filament.admin.pages.income-statement-page';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?string $preset = 'month';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function mount(): void
    {
        $this->startDate = Carbon::now(config('app.display_timezone'))->startOfMonth()->toDateString();
        $this->endDate = Carbon::now(config('app.display_timezone'))->endOfMonth()->toDateString();
        $this->preset = 'month';
    }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;

        [$this->startDate, $this->endDate] = match ($preset) {
            'month' => [Carbon::now(config('app.display_timezone'))->startOfMonth()->toDateString(), Carbon::now(config('app.display_timezone'))->endOfMonth()->toDateString()],
            'last_month' => [Carbon::now(config('app.display_timezone'))->subMonthNoOverflow()->startOfMonth()->toDateString(), Carbon::now(config('app.display_timezone'))->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'quarter' => [Carbon::now(config('app.display_timezone'))->startOfQuarter()->toDateString(), Carbon::now(config('app.display_timezone'))->endOfQuarter()->toDateString()],
            'year' => [Carbon::now(config('app.display_timezone'))->startOfYear()->toDateString(), Carbon::now(config('app.display_timezone'))->endOfYear()->toDateString()],
            default => [null, null],
        };

        if (! in_array($preset, ['month', 'last_month', 'quarter', 'year', 'all'], true)) {
            $this->preset = 'all';
        }
    }

    public function updatedStartDate(): void
    {
        $this->preset = null;
    }

    public function updatedEndDate(): void
    {
        $this->preset = null;
    }

    /**
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
    public function getStatementData(): array
    {
        if ($this->preset === 'all') {
            return app(FinancialStatementService::class)->getIncomeStatement('all');
        }

        return app(FinancialStatementService::class)->getIncomeStatement($this->startDate, $this->endDate);
    }

    public function exportCsv(): StreamedResponse
    {
        $data = $this->getStatementData();
        $filename = 'income-statement-'.($this->startDate ?? 'all').'-to-'.($this->endDate ?? 'present').'.csv';

        \app(AuditService::class)->recordExport('income statement', $filename, null);

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['B2Bhaat Marketplace — Income Statement (Profit & Loss)']);
            fputcsv($handle, ['Period:', $data['period_label'], 'Currency:', 'BDT (৳)', 'Generated At:', Carbon::now()->toDateTimeString()]);
            fputcsv($handle, []);

            // Section 1: Operating Revenue
            fputcsv($handle, ['OPERATING REVENUE']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Amount (BDT)']);
            foreach ($data['revenues']['accounts'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    number_format($row['amount'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, ['Total Operating Revenue', '', $data['revenues']['formatted_total']]);
            fputcsv($handle, []);

            // Section 2: Operating Expenses
            fputcsv($handle, ['OPERATING EXPENSES']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Amount (BDT)']);
            foreach ($data['expenses']['accounts'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    number_format($row['amount'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, ['Total Operating Expenses', '', $data['expenses']['formatted_total']]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['NET OPERATING INCOME / (LOSS)', '', $data['formatted_net_income']]);
            fputcsv($handle, ['Operating Margin %', '', $data['formatted_operating_margin']]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
}
