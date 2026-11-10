<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Filament\Admin\Pages\CashFlowStatementPage;
use App\Models\Accounting\AccountingAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACC-18: statement lines follow each account's normal balance, so a contra account (for example sales returns
 * under revenue) is shown as a deduction rather than an unexplained negative, and a cash flow statement shows how
 * the platform's cash and bank balance moved.
 */
class FinancialStatementSignsAndCashFlowTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $statements;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->statements = app(FinancialStatementService::class);
    }

    private function account(string $code, string $name, AccountType $type, NormalBalance $normalBalance): AccountingAccount
    {
        return AccountingAccount::create([
            'group_id' => AccountingAccount::where('type', $type)->value('group_id'),
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function postEntry(string $date, string $event, array $lines): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: $date,
            financialEvent: $event,
            idempotencyKey: 'acc18-'.(++$this->sequence),
            description: 'Test entry',
            lines: $lines,
        ));
    }

    public function test_a_contra_revenue_account_is_shown_as_a_deduction(): void
    {
        $returns = $this->account('4090', 'Sales Returns', AccountType::REVENUE, NormalBalance::DEBIT);

        $this->postEntry('2026-11-05', 'manual_journal', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 1000.00),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 1000.00),
        ]);
        $this->postEntry('2026-11-06', 'manual_journal', [
            new JournalLineData(accountId: $returns->id, debit: 200.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 200.00),
        ]);

        $statement = $this->statements->getIncomeStatement('2026-11-01', '2026-11-30');
        $row = collect($statement['revenues']['accounts'])->firstWhere('code', '4090');

        $this->assertTrue($row['is_contra']);
        $this->assertSame(200.0, $row['amount']);
        $this->assertSame('(৳200.00)', $row['formatted_amount']);
        $this->assertFalse(collect($statement['revenues']['accounts'])->firstWhere('code', '4010')['is_contra']);
        $this->assertSame(800.0, $statement['revenues']['total']);
        $this->assertSame(800.0, $statement['net_income']);
    }

    public function test_a_contra_asset_is_shown_as_a_deduction_and_the_balance_sheet_still_balances(): void
    {
        $allowance = $this->account('1090', 'Allowance for Doubtful Receivables', AccountType::ASSET, NormalBalance::CREDIT);

        $this->postEntry('2026-11-05', 'manual_journal', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 5000.00),
            new JournalLineData(accountSlug: 'owner_equity', credit: 5000.00),
        ]);
        $this->postEntry('2026-11-06', 'manual_journal', [
            new JournalLineData(accountSlug: 'promotional_discount_expense', debit: 300.00),
            new JournalLineData(accountId: $allowance->id, credit: 300.00),
        ]);

        $sheet = $this->statements->getBalanceSheet('2026-11-30');
        $row = collect($sheet['assets']['accounts'])->firstWhere('code', '1090');

        $this->assertTrue($row['is_contra']);
        $this->assertSame(300.0, $row['amount']);
        $this->assertSame(4700.0, $sheet['assets']['total']);
        $this->assertTrue($sheet['is_balanced']);
    }

    public function test_cash_flow_shows_opening_movements_by_activity_and_closing_cash(): void
    {
        // Before the period: owner puts in 10,000
        $this->postEntry('2026-10-20', 'manual_journal', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 10000.00),
            new JournalLineData(accountSlug: 'owner_equity', credit: 10000.00),
        ]);

        // In the period
        $this->postEntry('2026-11-03', 'subscription_paid', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 1500.00),
            new JournalLineData(accountSlug: 'subscription_revenue', credit: 1500.00),
        ]);
        $this->postEntry('2026-11-04', 'payout_disbursed', [
            new JournalLineData(accountSlug: 'reserved_payout_payable', debit: 4000.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 4000.00),
        ]);
        $this->postEntry('2026-11-05', 'manual_journal', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 2000.00),
            new JournalLineData(accountSlug: 'owner_equity', credit: 2000.00),
        ]);
        // Moving money between cash and bank is not a cash flow
        $this->postEntry('2026-11-06', 'manual_journal', [
            new JournalLineData(accountSlug: 'cash_on_hand', debit: 500.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 500.00),
        ]);
        // Money still held by a gateway is not cash yet
        $this->postEntry('2026-11-07', 'order_prepaid_received', [
            new JournalLineData(accountSlug: 'gateway_clearing', debit: 900.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00),
        ]);
        // After the period
        $this->postEntry('2026-12-02', 'subscription_paid', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 700.00),
            new JournalLineData(accountSlug: 'subscription_revenue', credit: 700.00),
        ]);

        $cashFlow = $this->statements->getCashFlowStatement('2026-11-01', '2026-11-30');

        $this->assertSame(10000.0, $cashFlow['opening_cash']);
        $this->assertSame(1500.0, $cashFlow['operating']['inflows']);
        $this->assertSame(4000.0, $cashFlow['operating']['outflows']);
        $this->assertSame(-2500.0, $cashFlow['operating']['net']);
        $this->assertSame(2000.0, $cashFlow['financing']['net']);
        $this->assertSame(-500.0, $cashFlow['net_change']);
        $this->assertSame(9500.0, $cashFlow['closing_cash']);
        $this->assertTrue($cashFlow['is_consistent']);

        $lines = collect($cashFlow['operating']['lines'])->keyBy('event');
        $this->assertSame(1500.0, $lines['subscription_paid']['net']);
        $this->assertSame('Subscription payments', $lines['subscription_paid']['label']);
        $this->assertSame(-4000.0, $lines['payout_disbursed']['net']);
        $this->assertArrayNotHasKey('order_prepaid_received', $lines->all());
    }

    public function test_admin_can_open_the_cash_flow_page(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->postEntry(now(config('app.display_timezone'))->toDateString(), 'subscription_paid', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 1500.00),
            new JournalLineData(accountSlug: 'subscription_revenue', credit: 1500.00),
        ]);

        $this->get(CashFlowStatementPage::getUrl())->assertSuccessful();

        Livewire::test(CashFlowStatementPage::class)
            ->assertSee('Cash Flow Statement')
            ->assertSee('Operating activities')
            ->assertSee('Subscription payments')
            ->assertSee('৳1,500.00');
    }

    public function test_customers_cannot_open_the_cash_flow_page(): void
    {
        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer'])->id,
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(CashFlowStatementPage::getUrl())->assertForbidden();
    }
}
