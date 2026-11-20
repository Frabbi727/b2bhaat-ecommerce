<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts;

use App\Filament\Seller\Pages\Accounts\Concerns\InteractsWithSellerBooks;
use App\Services\SellerAccounting\SellerFinancialReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Seller books: what the business owns, what it owes, and the owner's stake, on a given day.
 */
class BalanceSheet extends Page
{
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Balance sheet';

    protected static ?string $title = 'Balance Sheet';

    protected static ?string $slug = 'accounts/balance-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected string $view = 'filament.seller.pages.accounts.balance-sheet';

    #[Url(as: 'date')]
    public ?string $asOf = null;

    public function mount(): void
    {
        $this->asOf ??= CarbonImmutable::now(config('app.display_timezone'))->toDateString();
    }

    public function asOfDate(): ?string
    {
        return $this->asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf) === 1 ? $this->asOf : null;
    }

    public function asOfLabel(): string
    {
        return $this->asOfDate() !== null ? CarbonImmutable::parse($this->asOfDate())->format('d M Y') : 'today';
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(SellerFinancialReportService::class)->balanceSheet($this->sellerId(), $this->asOfDate());
    }

    public function exportCsv(): StreamedResponse
    {
        $report = $this->getReport();
        $rows = [['Balance Sheet'], ['As of', $this->asOfLabel()], []];

        foreach (['assets' => 'ASSETS', 'liabilities' => 'LIABILITIES', 'equity' => 'CAPITAL'] as $key => $title) {
            $rows[] = [$title];
            foreach ($report[$key] as $row) {
                $rows[] = [$row['code'], $row['name'], $this->csvAmount($row['amount'])];
            }
            if ($key === 'equity') {
                $rows[] = ['', 'Profit to date', $this->csvAmount($report['profit_to_date'])];
            }
            $rows[] = ['', 'Total '.strtolower($title), $this->csvAmount($report[$key === 'equity' ? 'total_equity' : 'total_'.$key])];
            $rows[] = [];
        }

        $rows[] = ['', 'Liabilities + capital', $this->csvAmount($report['total_liabilities'] + $report['total_equity'])];

        return $this->csvDownload('seller balance sheet', 'balance-sheet-'.($this->asOfDate() ?? 'today').'.csv', $rows);
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
