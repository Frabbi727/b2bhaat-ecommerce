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
 * Seller books: which products make money — sales less returns, cost of goods and B2Bhaat commission, per product.
 */
class ProductProfit extends Page
{
    use HasReportPeriod;
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Profit by product';

    protected static ?string $title = 'Profit by Product';

    protected static ?string $slug = 'accounts/product-profit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected string $view = 'filament.seller.pages.accounts.product-profit';

    /**
     * @return list<array<string, mixed>>
     */
    public function getReport(): array
    {
        return app(SellerFinancialReportService::class)->productProfit($this->sellerId(), $this->periodFrom(), $this->periodTo());
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = [['Profit by Product'], ['Period', $this->periodLabel()], [], ['Product', 'Sales', 'Returns', 'Net sales', 'Cost of goods', 'Gross profit', 'B2Bhaat commission', 'Profit', 'Margin %']];
        foreach ($this->getReport() as $row) {
            $rows[] = [
                $row['title'],
                $this->csvAmount($row['sales']),
                $this->csvAmount($row['returns']),
                $this->csvAmount($row['net_sales']),
                $this->csvAmount($row['cost']),
                $this->csvAmount($row['gross_profit']),
                $this->csvAmount($row['commission']),
                $this->csvAmount($row['profit']),
                $row['margin'],
            ];
        }

        return $this->csvDownload('seller product profit', 'product-profit-'.$this->periodFileSuffix().'.csv', $rows);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action('exportCsv'),
        ];
    }
}
