<?php

declare(strict_types=1);

namespace Tests\Feature\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use App\Services\SellerAccounting\SellerEntryDraft;
use App\Services\SellerAccounting\SellerFinancialReportService;
use App\Services\SellerAccounting\SellerJournalPostingService;
use App\Services\SellerAccounting\SellerManualEntryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SellerAccounting\Concerns\BuildsSellerBooksFixtures;
use Tests\TestCase;

class SellerManualEntriesAndReportsTest extends TestCase
{
    use BuildsSellerBooksFixtures;
    use RefreshDatabase;

    private SellerManualEntryService $entries;

    private SellerFinancialReportService $reports;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSellerBooksPrerequisites();
        $this->entries = app(SellerManualEntryService::class);
        $this->reports = app(SellerFinancialReportService::class);
        $this->today = now(config('app.display_timezone'))->toDateString();
    }

    private function accountId(User $seller, string $slug): int
    {
        return app(Chart::class)->accountIds($seller->id)[$slug];
    }

    public function test_investment_stock_and_expenses_give_the_right_profit_and_a_balanced_balance_sheet(): void
    {
        $seller = $this->makeSeller();
        $cash = $this->accountId($seller, Chart::CASH);
        $bank = $this->accountId($seller, Chart::BANK);

        $this->entries->recordCapital($seller->id, $seller->id, 50000, $this->today, $bank);
        $this->entries->recordStockPurchase($seller->id, $seller->id, 20000, $this->today, $bank);
        $this->entries->recordStockPurchase($seller->id, $seller->id, 5000, $this->today, $this->accountId($seller, Chart::ACCOUNTS_PAYABLE), 'On credit');
        $this->entries->recordExpense($seller->id, $seller->id, 3000, $this->today, $this->accountId($seller, 'rent'), $cash);
        $this->entries->recordDrawing($seller->id, $seller->id, 1000, $this->today, $bank);

        // A delivered B2Bhaat sale on top: ৳1,000 sales, ৳100 commission, ৳600 cost
        $product = $this->makeProduct($seller, 'Saree', 600);
        $subOrder = $this->makeSubOrder($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 600]], commission: 100);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        $pnl = $this->reports->profitAndLoss($seller->id, $this->today, $this->today);
        $this->assertSame(1000.0, $pnl['net_sales']);
        $this->assertSame(600.0, $pnl['cost_of_goods_sold']);
        $this->assertSame(3100.0, $pnl['total_expenses'], 'Rent + commission; the withdrawal is not an expense');
        $this->assertSame(-2700.0, $pnl['net_profit']);
        $this->assertFalse($pnl['is_profit']);

        $sheet = $this->reports->balanceSheet($seller->id, null);
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(5000.0, $sheet['total_liabilities']);
        $this->assertSame(-2700.0, $sheet['profit_to_date']);
        $this->assertSame(46300.0, $sheet['total_equity'], '50,000 capital − 1,000 withdrawn − 2,700 loss');

        $position = $this->reports->position($seller->id);
        $this->assertSame(26000.0, $position['cash_and_bank'], '50,000 − 20,000 − 1,000 in the bank; −3,000 cash');
        $this->assertSame(24400.0, $position['inventory'], '25,000 bought − 600 sold');
        $this->assertSame(50000.0, $position['capital_invested']);
        $this->assertSame(900.0, $position['receivable_from_b2bhaat']);

        $this->assertTrue($this->reports->trialBalance($seller->id, null)['is_balanced']);
    }

    public function test_opening_balances_put_the_difference_into_opening_equity(): void
    {
        $seller = $this->makeSeller();

        $this->entries->recordOpeningBalances($seller->id, $seller->id, $this->today, [
            'cash' => 5000, 'bank' => 15000, 'inventory' => 30000, 'supplier_dues' => 8000, 'loans' => null,
        ]);

        $this->assertSame(-42000.0, $this->debitBalance($seller, Chart::OPENING_BALANCE));
        $this->assertTrue($this->reports->balanceSheet($seller->id, null)['is_balanced']);
    }

    public function test_stock_value_at_cost_is_suggested_from_products_in_stock(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Lungi', 150);
        $product->variations()->firstOrFail()->update(['stock_quantity' => 40]);

        $this->assertSame(6000.0, $this->entries->stockValueAtCost($seller->id));
    }

    public function test_manual_journal_must_balance_and_cannot_touch_the_wallet_accounts(): void
    {
        $seller = $this->makeSeller();
        $cash = $this->accountId($seller, Chart::CASH);
        $bank = $this->accountId($seller, Chart::BANK);

        $entry = $this->entries->postManualJournal($seller->id, $seller->id, $this->today, 'Cash deposited', [
            ['account_id' => $bank, 'debit' => 2000],
            ['account_id' => $cash, 'credit' => 2000],
        ]);
        $this->assertSame(SellerEntryType::Manual, $entry->source_type);

        try {
            $this->entries->postManualJournal($seller->id, $seller->id, $this->today, 'Wrong', [
                ['account_id' => $bank, 'debit' => 2000],
                ['account_id' => $cash, 'credit' => 1500],
            ]);
            $this->fail('An unbalanced journal was accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('must be equal', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->entries->postManualJournal($seller->id, $seller->id, $this->today, 'Fake wallet money', [
            ['account_id' => $this->accountId($seller, Chart::B2BHAAT_AVAILABLE), 'debit' => 9999],
            ['account_id' => $cash, 'credit' => 9999],
        ]);
    }

    public function test_a_seller_cannot_post_to_another_sellers_account(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();

        $this->expectException(DomainException::class);
        $this->entries->postManualJournal($seller->id, $seller->id, $this->today, 'Sneaky', [
            ['account_id' => $this->accountId($other, Chart::CASH), 'debit' => 100],
            ['account_id' => $this->accountId($seller, Chart::CASH), 'credit' => 100],
        ]);
    }

    public function test_the_posting_service_rejects_lines_on_another_sellers_account_even_by_id(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();

        $draft = new SellerEntryDraft($seller->id, SellerEntryType::Manual, 'x', $this->today, 'test:cross-seller');
        $draft->debit($this->accountId($other, Chart::CASH), 10)->credit(Chart::CASH, 10);

        $this->expectException(DomainException::class);
        app(SellerJournalPostingService::class)->post($draft);
    }

    public function test_reversing_a_manual_entry_cancels_it_once_and_only_manual_entries_can_be_reversed(): void
    {
        $seller = $this->makeSeller();
        $expense = $this->entries->recordExpense($seller->id, $seller->id, 750, $this->today, $this->accountId($seller, 'packaging'), $this->accountId($seller, Chart::CASH));
        $posting = app(SellerJournalPostingService::class);

        $reversal = $posting->reverse($expense, $seller->id, 'Typed the wrong amount');

        $this->assertSame($expense->id, $reversal->reversal_of_id);
        $this->assertSame(0.0, $this->debitBalance($seller, 'packaging'));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::CASH));
        $this->assertFalse($expense->fresh()->canBeReversed());

        try {
            $posting->reverse($expense->fresh(), $seller->id, 'again');
            $this->fail('An entry was reversed twice');
        } catch (DomainException) {
            $this->assertSame(2, SellerJournalEntry::where('seller_id', $seller->id)->count());
        }

        $product = $this->makeProduct($seller, 'Panjabi', 400);
        $subOrder = $this->makeSubOrder($seller, [['product' => $product, 'quantity' => 1, 'unit_price' => 900, 'unit_cost' => 400]], commission: 90);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);
        $sale = SellerJournalEntry::where('seller_id', $seller->id)->where('source_type', SellerEntryType::Sale)->firstOrFail();

        $this->expectException(DomainException::class);
        $posting->reverse($sale, $seller->id, 'Not allowed');
    }

    public function test_dates_in_the_future_and_zero_amounts_are_refused(): void
    {
        $seller = $this->makeSeller();
        $cash = $this->accountId($seller, Chart::CASH);

        try {
            $this->entries->recordCapital($seller->id, $seller->id, 100, now()->addDays(3)->toDateString(), $cash);
            $this->fail('A future date was accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('future', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->entries->recordCapital($seller->id, $seller->id, 0, $this->today, $cash);
    }

    public function test_seller_added_accounts_get_codes_in_their_type_range_and_unique_names(): void
    {
        $seller = $this->makeSeller();
        $chart = app(Chart::class);

        $first = $chart->addAccount($seller->id, 'Factory electricity', AccountType::EXPENSE);
        $second = $chart->addAccount($seller->id, 'Generator fuel', AccountType::EXPENSE);
        $wallet = $chart->addAccount($seller->id, 'bKash merchant', AccountType::ASSET);

        $this->assertSame(['5500', '5501', '1500'], [$first->code, $second->code, $wallet->code]);
        $this->assertArrayHasKey($wallet->id, $chart->moneyAccountOptions($seller->id), 'A seller-added asset can pay or receive money');
        $this->assertArrayHasKey($first->id, $chart->expenseAccountOptions($seller->id));

        $this->expectException(DomainException::class);
        $chart->addAccount($seller->id, 'factory ELECTRICITY', AccountType::EXPENSE);
    }

    public function test_period_filters_include_the_whole_end_day_and_the_ledger_carries_an_opening_balance(): void
    {
        $seller = $this->makeSeller();
        $cash = $this->accountId($seller, Chart::CASH);
        $yesterday = now(config('app.display_timezone'))->subDay()->toDateString();

        $this->entries->recordCapital($seller->id, $seller->id, 1000, $yesterday, $cash);
        $this->entries->recordExpense($seller->id, $seller->id, 200, $this->today, $this->accountId($seller, 'transport'), $cash);

        $todayOnly = $this->reports->profitAndLoss($seller->id, $this->today, $this->today);
        $this->assertSame(200.0, $todayOnly['total_expenses']);

        $ledger = $this->reports->generalLedger($seller->id, $cash, $this->today, $this->today);
        $this->assertSame(1000.0, $ledger['opening_balance']);
        $this->assertCount(1, $ledger['lines']);
        $this->assertSame(800.0, $ledger['closing_balance']);
    }

    public function test_entry_numbers_run_per_seller(): void
    {
        $first = $this->makeSeller();
        $second = $this->makeSeller();

        $a = $this->entries->recordCapital($first->id, $first->id, 10, $this->today, $this->accountId($first, Chart::CASH));
        $b = $this->entries->recordCapital($second->id, $second->id, 10, $this->today, $this->accountId($second, Chart::CASH));
        $c = $this->entries->recordCapital($first->id, $first->id, 10, $this->today, $this->accountId($first, Chart::CASH));

        $this->assertSame(['SJ-000001', 'SJ-000001', 'SJ-000002'], [$a->entry_number, $b->entry_number, $c->entry_number]);
        $this->assertSame(0, SellerAccount::where('seller_id', $first->id)->where('is_system', false)->count());
    }
}
