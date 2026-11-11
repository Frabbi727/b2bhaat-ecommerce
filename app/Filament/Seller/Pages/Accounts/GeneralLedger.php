<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts;

use App\Filament\Seller\Pages\Accounts\Concerns\HasReportPeriod;
use App\Filament\Seller\Pages\Accounts\Concerns\InteractsWithSellerBooks;
use App\Services\SellerAccounting\SellerChartOfAccountsService;
use App\Services\SellerAccounting\SellerFinancialReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Seller books: the general ledger for one account — every movement in a period, with the running balance. Sellers
 * see it as "Account history" (UX-2 plain wording).
 */
class GeneralLedger extends Page
{
    use HasReportPeriod;
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Account history';

    protected static ?string $title = 'Account History';

    protected static ?string $slug = 'accounts/account-history';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected string $view = 'filament.seller.pages.accounts.general-ledger';

    #[Url]
    public ?int $account = null;

    public function mount(): void
    {
        $options = $this->accountOptions();

        if ($this->account === null || ! array_key_exists($this->account, $options)) {
            $this->account = app(SellerChartOfAccountsService::class)->accountIds($this->sellerId())[SellerChartOfAccountsService::CASH] ?? array_key_first($options);
        }
    }

    /**
     * @return array<int, string>
     */
    public function accountOptions(): array
    {
        return app(SellerChartOfAccountsService::class)->accounts($this->sellerId())
            ->mapWithKeys(fn ($account): array => [$account->id => "{$account->code} · {$account->name}"])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(SellerFinancialReportService::class)->generalLedger($this->sellerId(), (int) $this->account, $this->periodFrom(), $this->periodTo());
    }

    public function exportCsv(): StreamedResponse
    {
        $report = $this->getReport();
        $account = $report['account'];

        $rows = [['Account History (general ledger)'], ['Account', "{$account->code} {$account->name}"], ['Period', $this->periodLabel()], [], ['Date', 'Entry', 'Details', 'Debit', 'Credit', 'Balance']];
        $rows[] = ['', '', 'Opening balance', '', '', $this->csvAmount($report['opening_balance'])];
        foreach ($report['lines'] as $line) {
            $rows[] = [$line['date'], $line['entry_number'], trim($line['description'].' '.($line['narration'] ?? '')), $line['debit'] > 0 ? $this->csvAmount($line['debit']) : '', $line['credit'] > 0 ? $this->csvAmount($line['credit']) : '', $this->csvAmount($line['balance'])];
        }
        $rows[] = ['', '', 'Closing balance', $this->csvAmount($report['total_debit']), $this->csvAmount($report['total_credit']), $this->csvAmount($report['closing_balance'])];

        return $this->csvDownload('seller account history', 'account-history-'.$account->code.'-'.$this->periodFileSuffix().'.csv', $rows);
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
