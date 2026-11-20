<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages\Accounts;

use App\Filament\Seller\Pages\Accounts\Concerns\InteractsWithSellerBooks;
use App\Filament\Seller\Resources\SellerJournalEntries\Actions\RecordEntryActions;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Services\SellerAccounting\SellerFinancialReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Seller books: the seller's business at a glance — profit, money, stock, investment, and whether the books agree
 * with the B2Bhaat wallet.
 */
class AccountsOverview extends Page
{
    use InteractsWithSellerBooks;

    protected static UnitEnum|string|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'My Business Accounts';

    protected static ?string $slug = 'accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected string $view = 'filament.seller.pages.accounts.overview';

    public function getSubheading(): ?string
    {
        return 'Profit, money and stock from your own double-entry books. B2Bhaat sales, returns and payouts are recorded automatically.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $reports = app(SellerFinancialReportService::class);
        $now = CarbonImmutable::now(config('app.display_timezone'));
        $sellerId = $this->sellerId();

        return [
            'month' => $reports->profitAndLoss($sellerId, $now->startOfMonth()->toDateString(), $now->toDateString()),
            'year' => $reports->profitAndLoss($sellerId, $now->startOfYear()->toDateString(), $now->toDateString()),
            'position' => $reports->position($sellerId),
            'reconciliation' => $reports->walletReconciliation($sellerId),
            'months' => $this->monthlyProfit($reports, $now),
            'has_own_entries' => SellerJournalEntry::query()->where('seller_id', $sellerId)->where('source', SellerJournalEntry::SOURCE_MANUAL)->exists(),
        ];
    }

    /**
     * @return Collection<int, SellerJournalEntry>
     */
    public function getRecentEntries(): Collection
    {
        return SellerJournalEntry::query()
            ->where('seller_id', $this->sellerId())
            ->withSum('lines as total_amount', 'debit')
            ->latest('id')
            ->limit(6)
            ->get();
    }

    /**
     * Profit for each of the last six months, oldest first.
     *
     * @return list<array{label: string, profit: float}>
     */
    private function monthlyProfit(SellerFinancialReportService $reports, CarbonImmutable $now): array
    {
        $start = $now->subMonthsNoOverflow(5)->startOfMonth();
        $daily = $reports->dailyProfit($this->sellerId(), $start->toDateString(), $now->toDateString());

        $months = [];
        for ($month = $start; $month <= $now; $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $months[] = [
                'label' => $month->format('M'),
                'profit' => round((float) $daily->filter(fn (float $profit, string $day): bool => str_starts_with($day, $key))->sum(), 2),
            ];
        }

        return $months;
    }

    protected function getHeaderActions(): array
    {
        return [
            RecordEntryActions::group(),
        ];
    }
}
