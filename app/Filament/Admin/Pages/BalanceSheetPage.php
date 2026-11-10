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

class BalanceSheetPage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Balance sheet';

    protected static ?string $title = 'Balance Sheet';

    protected static ?string $slug = 'balance-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.admin.pages.balance-sheet-page';

    public ?string $asOfDate = null;

    public ?string $preset = 'today';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function mount(): void
    {
        $this->asOfDate = Carbon::now(config('app.display_timezone'))->toDateString();
        $this->preset = 'today';
    }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;

        $this->asOfDate = match ($preset) {
            'today' => Carbon::now(config('app.display_timezone'))->toDateString(),
            'month' => Carbon::now(config('app.display_timezone'))->endOfMonth()->toDateString(),
            'last_month' => Carbon::now(config('app.display_timezone'))->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'quarter' => Carbon::now(config('app.display_timezone'))->endOfQuarter()->toDateString(),
            'year' => Carbon::now(config('app.display_timezone'))->endOfYear()->toDateString(),
            default => Carbon::now(config('app.display_timezone'))->toDateString(),
        };

        if (! in_array($preset, ['today', 'month', 'last_month', 'quarter', 'year'], true)) {
            $this->preset = 'today';
        }
    }

    public function updatedAsOfDate(): void
    {
        $this->preset = null;
    }

    /**
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
    public function getStatementData(): array
    {
        return app(FinancialStatementService::class)->getBalanceSheet($this->asOfDate);
    }

    public function exportCsv(): StreamedResponse
    {
        $data = $this->getStatementData();
        $filename = 'balance-sheet-'.($this->asOfDate ?? Carbon::now(config('app.display_timezone'))->toDateString()).'.csv';

        \app(AuditService::class)->recordExport('balance sheet', $filename, null);

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['B2Bhaat Marketplace — Balance Sheet Statement']);
            fputcsv($handle, ['As of Date:', $data['formatted_as_of_date'], 'Currency:', 'BDT (৳)', 'Generated At:', Carbon::now()->toDateTimeString()]);
            fputcsv($handle, []);

            // Section 1: CURRENT ASSETS
            fputcsv($handle, ['CURRENT ASSETS']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Amount (BDT)']);
            foreach ($data['assets']['accounts'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    number_format($row['is_contra'] ? -$row['amount'] : $row['amount'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, ['Total Current Assets', '', $data['assets']['formatted_total']]);
            fputcsv($handle, []);

            // Section 2: CURRENT LIABILITIES
            fputcsv($handle, ['CURRENT LIABILITIES']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Amount (BDT)']);
            foreach ($data['liabilities']['accounts'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    number_format($row['is_contra'] ? -$row['amount'] : $row['amount'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, ['Total Current Liabilities', '', $data['liabilities']['formatted_total']]);
            fputcsv($handle, []);

            // Section 3: EQUITY & CAPITAL
            fputcsv($handle, ['EQUITY & CAPITAL']);
            fputcsv($handle, ['Account Code', 'Account Name', 'Amount (BDT)']);
            foreach ($data['equity']['capital_accounts'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    number_format($row['is_contra'] ? -$row['amount'] : $row['amount'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, ['Cumulative Net Income (Unclosed)', '', $data['equity']['formatted_current_net_income']]);
            fputcsv($handle, ['Total Equity & Retained Earnings', '', $data['equity']['formatted_total']]);
            fputcsv($handle, []);

            // Summary Equilibrium Check
            fputcsv($handle, ['TOTAL LIABILITIES & EQUITY', '', $data['formatted_total_liabilities_and_equity']]);
            fputcsv($handle, ['EQUILIBRIUM STATUS', $data['is_balanced'] ? 'BALANCED' : 'OUT OF BALANCE', $data['formatted_discrepancy']]);

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
