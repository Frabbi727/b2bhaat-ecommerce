<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts;

use App\Filament\Seller\Pages\Accounts\Concerns\HasReportPeriod;
use App\Filament\Seller\Pages\Accounts\Concerns\InteractsWithSellerBooks;
use App\Services\SellerAccounting\SellerFinancialReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Seller books: income less cost of goods and expenses for a period — the seller's profit or loss.
 */
class ProfitAndLoss extends Page
{
    use HasReportPeriod;
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Profit & loss';

    protected static ?string $title = 'Profit & Loss';

    protected static ?string $slug = 'accounts/profit-and-loss';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected string $view = 'filament.seller.pages.accounts.profit-and-loss';

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(SellerFinancialReportService::class)->profitAndLoss($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    public function exportCsv(): StreamedResponse
    {
        $report = $this->getReport();
        $rows = [['Profit & Loss'], ['Period', $this->periodLabel()], [], ['INCOME']];
        foreach ($report['income'] as $row) {
            $rows[] = [$row['code'], $row['name'], $this->csvAmount($row['amount'])];
        }
        $rows[] = ['', 'Net sales', $this->csvAmount($report['net_sales'])];
        $rows[] = ['', 'Cost of goods sold', $this->csvAmount(-$report['cost_of_goods_sold'])];
        $rows[] = ['', 'Gross profit', $this->csvAmount($report['gross_profit'])];
        $rows[] = [];
        $rows[] = ['EXPENSES'];
        foreach ($report['expenses'] as $row) {
            $rows[] = [$row['code'], $row['name'], $this->csvAmount(-$row['amount'])];
        }
        $rows[] = ['', 'Total expenses', $this->csvAmount(-$report['total_expenses'])];
        $rows[] = [];
        $rows[] = ['', $report['is_profit'] ? 'NET PROFIT' : 'NET LOSS', $this->csvAmount($report['net_profit'])];

        return $this->csvDownload('seller profit and loss', 'profit-and-loss-'.$this->periodFileSuffix().'.csv', $rows);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->extraAttributes(['onclick' => 'window.print(); return false;']),
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action('exportCsv'),
        ];
    }
}
