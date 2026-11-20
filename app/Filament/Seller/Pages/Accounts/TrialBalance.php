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
 * Seller books: every account's debit or credit balance on a day; the two columns are always equal.
 */
class TrialBalance extends Page
{
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Trial balance';

    protected static ?string $title = 'Trial Balance';

    protected static ?string $slug = 'accounts/trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected string $view = 'filament.seller.pages.accounts.trial-balance';

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

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(SellerFinancialReportService::class)->trialBalance($this->sellerId(), $this->asOfDate());
    }

    public function exportCsv(): StreamedResponse
    {
        $report = $this->getReport();
        $rows = [['Trial Balance'], ['As of', $this->asOfDate() ?? 'today'], [], ['Code', 'Account', 'Type', 'Debit', 'Credit']];
        foreach ($report['rows'] as $row) {
            $rows[] = [$row['code'], $row['name'], $row['type'], $row['debit'] > 0 ? $this->csvAmount($row['debit']) : '', $row['credit'] > 0 ? $this->csvAmount($row['credit']) : ''];
        }
        $rows[] = ['', 'Total', '', $this->csvAmount($report['total_debit']), $this->csvAmount($report['total_credit'])];

        return $this->csvDownload('seller trial balance', 'trial-balance-'.($this->asOfDate() ?? 'today').'.csv', $rows);
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
