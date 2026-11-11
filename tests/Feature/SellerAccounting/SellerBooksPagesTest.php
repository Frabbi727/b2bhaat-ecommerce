<?php

declare(strict_types=1);

namespace Tests\Feature\SellerAccounting;

use App\Filament\Seller\Pages\Accounts\AccountsOverview;
use App\Filament\Seller\Pages\Accounts\BalanceSheet;
use App\Filament\Seller\Pages\Accounts\GeneralLedger;
use App\Filament\Seller\Pages\Accounts\ProductProfit;
use App\Filament\Seller\Pages\Accounts\ProfitAndLoss;
use App\Filament\Seller\Pages\Accounts\TrialBalance;
use App\Filament\Seller\Resources\SellerAccounts\Pages\ListSellerAccounts;
use App\Filament\Seller\Resources\SellerJournalEntries\Pages\ListSellerJournalEntries;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use App\Services\SellerAccounting\SellerManualEntryService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\SellerAccounting\Concerns\BuildsSellerBooksFixtures;
use Tests\TestCase;

class SellerBooksPagesTest extends TestCase
{
    use BuildsSellerBooksFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSellerBooksPrerequisites();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountPages(): array
    {
        return [
            'overview' => ['/seller/accounts'],
            'journal' => ['/seller/accounts/journal'],
            'profit and loss' => ['/seller/accounts/profit-and-loss'],
            'balance sheet' => ['/seller/accounts/balance-sheet'],
            'product profit' => ['/seller/accounts/product-profit'],
            'trial balance' => ['/seller/accounts/trial-balance'],
            'account history' => ['/seller/accounts/account-history'],
            'chart of accounts' => ['/seller/accounts/chart-of-accounts'],
        ];
    }

    private function actAsSeller(User $seller): void
    {
        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    /** A seller with a sale, an investment and an expense, so every page has something to show. */
    private function sellerWithActivity(): User
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Jamdani Saree', 2500);
        $subOrder = $this->makeSubOrder($seller, [['product' => $product, 'quantity' => 2, 'unit_price' => 4000, 'unit_cost' => 2500]], commission: 800);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        $ids = app(Chart::class)->accountIds($seller->id);
        $today = now(config('app.display_timezone'))->toDateString();
        app(SellerManualEntryService::class)->recordCapital($seller->id, $seller->id, 100000, $today, $ids[Chart::BANK]);
        app(SellerManualEntryService::class)->recordExpense($seller->id, $seller->id, 1200, $today, $ids['rent'], $ids[Chart::CASH]);

        return $seller;
    }

    #[DataProvider('accountPages')]
    public function test_an_approved_seller_can_open_every_accounts_page(string $url): void
    {
        $this->actAsSeller($this->sellerWithActivity());

        $this->get($url)->assertSuccessful();
    }

    #[DataProvider('accountPages')]
    public function test_buyers_and_unapproved_sellers_cannot_open_accounts_pages(string $url): void
    {
        $this->actAsSeller($this->makeBuyer());
        $this->get($url)->assertForbidden();

        $this->actAsSeller($this->makeSeller(approved: false));
        $this->get($url)->assertForbidden();
    }

    public function test_reports_show_the_sellers_own_figures(): void
    {
        $seller = $this->sellerWithActivity();
        $this->actAsSeller($seller);

        Livewire::test(ProfitAndLoss::class)
            ->assertSee('৳8,000.00')
            ->assertSee('Net profit')
            ->assertSee('৳1,000.00');

        Livewire::test(ProductProfit::class)->assertSee('Jamdani Saree')->assertSee('৳2,200.00');
        Livewire::test(BalanceSheet::class)->assertSee('৳100,000.00')->assertDontSee('does not balance');
        Livewire::test(TrialBalance::class)->assertSee('Debits equal credits');
        Livewire::test(AccountsOverview::class)->assertSee('Matches your B2Bhaat wallet');
    }

    public function test_journal_lists_only_the_signed_in_sellers_entries(): void
    {
        $seller = $this->sellerWithActivity();
        $other = $this->sellerWithActivity();
        $this->actAsSeller($seller);

        Livewire::test(ListSellerJournalEntries::class)
            ->assertCanSeeTableRecords(SellerJournalEntry::where('seller_id', $seller->id)->get())
            ->assertCanNotSeeTableRecords(SellerJournalEntry::where('seller_id', $other->id)->get());

        Livewire::test(ListSellerAccounts::class)
            ->assertCanSeeTableRecords(SellerAccount::where('seller_id', $seller->id)->get())
            ->assertCanNotSeeTableRecords(SellerAccount::where('seller_id', $other->id)->get());
    }

    public function test_general_ledger_ignores_another_sellers_account_in_the_url(): void
    {
        $seller = $this->sellerWithActivity();
        $other = $this->sellerWithActivity();
        $this->actAsSeller($seller);
        $othersBank = app(Chart::class)->accountIds($other->id)[Chart::BANK];

        Livewire::withQueryParams(['account' => $othersBank])
            ->test(GeneralLedger::class)
            ->assertSet('account', app(Chart::class)->accountIds($seller->id)[Chart::CASH]);

        Livewire::test(GeneralLedger::class)
            ->set('account', $othersBank)
            ->assertNotFound();
    }

    public function test_seller_can_record_an_expense_from_the_journal_page(): void
    {
        $seller = $this->makeSeller();
        $this->actAsSeller($seller);
        $ids = app(Chart::class)->accountIds($seller->id);

        Livewire::test(ListSellerJournalEntries::class)
            ->callAction(TestAction::make('recordExpense'), [
                'expense_account_id' => $ids['packaging'],
                'amount' => '450',
                'paid_from_account_id' => $ids[Chart::CASH],
                'entry_date' => now(config('app.display_timezone'))->toDateString(),
                'note' => 'Poly bags',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Expense recorded');

        $this->assertSame(450.0, $this->debitBalance($seller, 'packaging'));
        $this->assertSame(-450.0, $this->debitBalance($seller, Chart::CASH));
    }

    public function test_seller_can_reverse_their_own_entry_but_not_an_automatic_one(): void
    {
        $seller = $this->sellerWithActivity();
        $this->actAsSeller($seller);

        $expense = SellerJournalEntry::where('seller_id', $seller->id)->where('source', SellerJournalEntry::SOURCE_MANUAL)->latest('id')->firstOrFail();
        $sale = SellerJournalEntry::where('seller_id', $seller->id)->where('source', SellerJournalEntry::SOURCE_AUTO)->firstOrFail();

        Livewire::test(ListSellerJournalEntries::class)
            ->assertActionHidden(TestAction::make('reverse')->table($sale))
            ->callAction(TestAction::make('reverse')->table($expense), ['reason' => 'Duplicate'])
            ->assertNotified('Entry reversed');

        $this->assertSame(0.0, $this->debitBalance($seller, 'rent'));
    }

    public function test_seller_can_add_an_expense_account(): void
    {
        $seller = $this->makeSeller();
        $this->actAsSeller($seller);

        Livewire::test(ListSellerAccounts::class)
            ->callAction('addAccount', ['name' => 'Courier bags', 'type' => 'expense'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('seller_accounts', ['seller_id' => $seller->id, 'name' => 'Courier bags', 'code' => '5500', 'is_system' => false]);
    }

    public function test_reports_export_to_csv(): void
    {
        $this->actAsSeller($this->sellerWithActivity());

        foreach ([ProfitAndLoss::class, BalanceSheet::class, TrialBalance::class, GeneralLedger::class, ProductProfit::class] as $page) {
            Livewire::test($page)->call('exportCsv')->assertFileDownloaded();
        }
    }

    public function test_accounts_pages_still_default_to_this_month(): void
    {
        $this->actAsSeller($this->makeSeller());

        Livewire::test(ProfitAndLoss::class)
            ->assertSet('preset', 'month')
            ->assertSet('startDate', now(config('app.display_timezone'))->startOfMonth()->toDateString());
    }

    public function test_rolling_presets_set_a_range_ending_today(): void
    {
        $this->actAsSeller($this->makeSeller());
        $today = now(config('app.display_timezone'));

        Livewire::test(ProfitAndLoss::class)
            ->call('setPreset', '30d')
            ->assertSet('preset', '30d')
            ->assertSet('endDate', $today->toDateString())
            ->assertSet('startDate', $today->copy()->subDays(29)->toDateString());
    }
}
