<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $service;

    private JournalPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->postingService = app(JournalPostingService::class);
        $this->service = app(FinancialStatementService::class);
    }

    public function test_income_statement_computes_accurate_revenue_expense_and_net_income(): void
    {
        // 4010 Commission Revenue: credit 10,000.00
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_rev_1',
            description: 'Commission revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 10000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 10000.00),
            ],
        ));

        // 4020 Subscription Revenue: credit 2,000.00
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-10',
            financialEvent: AccountingEvent::SUBSCRIPTION_PAID->value,
            idempotencyKey: 'test_is_rev_2',
            description: 'Subscription revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 2000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'subscription_revenue', debit: 0.00, credit: 2000.00),
            ],
        ));

        // 5010 Promotional Discount Expense: debit 1,500.00
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-15',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_exp_1',
            description: 'Platform discount subsidized',
            lines: [
                new JournalLineData(accountSlug: 'promotional_discount_expense', debit: 1500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 0.00, credit: 1500.00),
            ],
        ));

        // 5020 Gateway Fee Expense: debit 500.00
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-18',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_exp_2',
            description: 'Payment gateway fee',
            lines: [
                new JournalLineData(accountSlug: 'gateway_fee_expense', debit: 500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 0.00, credit: 500.00),
            ],
        ));

        $statement = $this->service->getIncomeStatement('2026-11-01', '2026-11-30');

        $this->assertSame('2026-11-01', $statement['start_date']);
        $this->assertSame('2026-11-30', $statement['end_date']);
        $this->assertNotEmpty($statement['period_label']);

        $this->assertSame(12000.00, $statement['revenues']['total']);
        $this->assertSame('৳12,000.00', $statement['revenues']['formatted_total']);

        $this->assertSame(2000.00, $statement['expenses']['total']);
        $this->assertSame('৳2,000.00', $statement['expenses']['formatted_total']);

        $this->assertSame(10000.00, $statement['net_income']);
        $this->assertSame('+৳10,000.00', $statement['formatted_net_income']);
        $this->assertTrue($statement['is_profit']);

        $this->assertSame(83.33, $statement['operating_margin_percentage']);
        $this->assertSame('83.33%', $statement['formatted_operating_margin']);

        // Check revenue account lines
        $commissionRow = collect($statement['revenues']['accounts'])->firstWhere('code', '4010');
        $this->assertNotNull($commissionRow);
        $this->assertSame(10000.00, $commissionRow['amount']);
        $this->assertSame('৳10,000.00', $commissionRow['formatted_amount']);

        $subscriptionRow = collect($statement['revenues']['accounts'])->firstWhere('code', '4020');
        $this->assertNotNull($subscriptionRow);
        $this->assertSame(2000.00, $subscriptionRow['amount']);
        $this->assertSame('৳2,000.00', $subscriptionRow['formatted_amount']);

        // Check expense account lines
        $discountRow = collect($statement['expenses']['accounts'])->firstWhere('code', '5010');
        $this->assertNotNull($discountRow);
        $this->assertSame(1500.00, $discountRow['amount']);
        $this->assertSame('৳1,500.00', $discountRow['formatted_amount']);

        $gatewayRow = collect($statement['expenses']['accounts'])->firstWhere('code', '5020');
        $this->assertNotNull($gatewayRow);
        $this->assertSame(500.00, $gatewayRow['amount']);
        $this->assertSame('৳500.00', $gatewayRow['formatted_amount']);
    }

    public function test_income_statement_computes_operating_margin_percentage_accurately(): void
    {
        // Net loss situation: Revenue 5,000, Expenses 8,000 => Net Loss -3,000
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_loss_rev',
            description: 'Commission revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 5000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 5000.00),
            ],
        ));

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-10',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_loss_exp',
            description: 'Courier delivery expense',
            lines: [
                new JournalLineData(accountSlug: 'courier_delivery_expense', debit: 8000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 0.00, credit: 8000.00),
            ],
        ));

        $statement = $this->service->getIncomeStatement('2026-11-01', '2026-11-30');

        $this->assertSame(5000.00, $statement['revenues']['total']);
        $this->assertSame(8000.00, $statement['expenses']['total']);
        $this->assertSame(-3000.00, $statement['net_income']);
        $this->assertSame('-৳3,000.00', $statement['formatted_net_income']);
        $this->assertFalse($statement['is_profit']);

        $this->assertSame(-60.00, $statement['operating_margin_percentage']);
        $this->assertSame('-60.00%', $statement['formatted_operating_margin']);
    }

    public function test_income_statement_handles_zero_revenue_without_division_by_zero(): void
    {
        // No revenue, only expense
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_zero_rev',
            description: 'Gateway fee expense',
            lines: [
                new JournalLineData(accountSlug: 'gateway_fee_expense', debit: 1000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 0.00, credit: 1000.00),
            ],
        ));

        $statement = $this->service->getIncomeStatement('2026-11-01', '2026-11-30');

        $this->assertSame(0.00, $statement['revenues']['total']);
        $this->assertSame(1000.00, $statement['expenses']['total']);
        $this->assertSame(-1000.00, $statement['net_income']);
        $this->assertSame('-৳1,000.00', $statement['formatted_net_income']);
        $this->assertFalse($statement['is_profit']);
        $this->assertSame(0.00, $statement['operating_margin_percentage']);
        $this->assertSame('0.00%', $statement['formatted_operating_margin']);
    }

    public function test_income_statement_filters_strictly_by_date_range(): void
    {
        // Prior month: 2026-10-25
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-10-25',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_oct',
            description: 'October revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 5000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 5000.00),
            ],
        ));

        // Current month: 2026-11-15
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-15',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_nov',
            description: 'November revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 3000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 3000.00),
            ],
        ));

        // Next month: 2026-12-05
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-12-05',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_is_dec',
            description: 'December revenue',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 7000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 7000.00),
            ],
        ));

        $statement = $this->service->getIncomeStatement('2026-11-01', '2026-11-30');

        $this->assertSame(3000.00, $statement['revenues']['total']);
        $this->assertSame(3000.00, $statement['net_income']);
        $this->assertSame('+৳3,000.00', $statement['formatted_net_income']);
    }

    public function test_balance_sheet_aggregates_assets_liabilities_and_equity(): void
    {
        // Capital contribution: Cash 15,000 / Owner Capital 10,000 / Seller Escrow 5,000
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_bs_init',
            description: 'Initial funding and escrow deposit',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 15000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'owner_equity', debit: 0.00, credit: 10000.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 5000.00),
            ],
        ));

        $balanceSheet = $this->service->getBalanceSheet('2026-11-15');

        $this->assertSame('2026-11-15', $balanceSheet['as_of_date']);
        $this->assertNotEmpty($balanceSheet['formatted_as_of_date']);

        $this->assertSame(15000.00, $balanceSheet['assets']['total']);
        $this->assertSame('৳15,000.00', $balanceSheet['assets']['formatted_total']);

        $this->assertSame(5000.00, $balanceSheet['liabilities']['total']);
        $this->assertSame('৳5,000.00', $balanceSheet['liabilities']['formatted_total']);

        $this->assertSame(10000.00, $balanceSheet['equity']['retained_earnings']);
        $this->assertSame(0.00, $balanceSheet['equity']['current_net_income']);
        $this->assertSame(10000.00, $balanceSheet['equity']['total']);
        $this->assertSame('৳10,000.00', $balanceSheet['equity']['formatted_total']);

        $this->assertSame(15000.00, $balanceSheet['total_liabilities_and_equity']);
        $this->assertSame('৳15,000.00', $balanceSheet['formatted_total_liabilities_and_equity']);

        $this->assertTrue($balanceSheet['is_balanced']);
        $this->assertSame(0.00, $balanceSheet['discrepancy']);
        $this->assertSame('৳0.00', $balanceSheet['formatted_discrepancy']);
    }

    public function test_balance_sheet_rolls_cumulative_net_income_into_equity_to_satisfy_accounting_equation(): void
    {
        // 1. Initial owner capital: 50,000
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-01',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_bs_roll_cap',
            description: 'Capital deposit',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 50000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'owner_equity', debit: 0.00, credit: 50000.00),
            ],
        ));

        // 2. Revenue earned: Commission 20,000 into Cash
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-03',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_roll_rev',
            description: 'Commission earnings',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 20000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 20000.00),
            ],
        ));

        // 3. Operating expense incurred: Gateway fee 3,000 paid from Cash
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-04',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_roll_exp',
            description: 'Gateway processing fee',
            lines: [
                new JournalLineData(accountSlug: 'gateway_fee_expense', debit: 3000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 0.00, credit: 3000.00),
            ],
        ));

        // 4. Liability incurred: Customer escrow 12,000 in Gateway clearing
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-05',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_roll_escrow',
            description: 'Customer order escrow held',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 12000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 12000.00),
            ],
        ));

        // Total Assets:
        // Cash on hand: 50,000 + 20,000 - 3,000 = 67,000
        // Gateway clearing: 12,000
        // Total Assets = 79,000.00
        //
        // Total Liabilities:
        // Seller escrow: 12,000.00
        //
        // Total Equity:
        // Retained Earnings (Owner Capital): 50,000.00
        // Current Net Income: 20,000 (Rev) - 3,000 (Exp) = 17,000.00
        // Total Equity = 50,000 + 17,000 = 67,000.00
        //
        // Total Liabilities & Equity = 12,000 + 67,000 = 79,000.00
        $balanceSheet = $this->service->getBalanceSheet('2026-11-10');

        $this->assertSame(79000.00, $balanceSheet['assets']['total']);
        $this->assertSame('৳79,000.00', $balanceSheet['assets']['formatted_total']);

        $this->assertSame(12000.00, $balanceSheet['liabilities']['total']);
        $this->assertSame('৳12,000.00', $balanceSheet['liabilities']['formatted_total']);

        $this->assertSame(50000.00, $balanceSheet['equity']['retained_earnings']);
        $this->assertSame(17000.00, $balanceSheet['equity']['current_net_income']);
        $this->assertSame('+৳17,000.00', $balanceSheet['equity']['formatted_current_net_income']);
        $this->assertSame(67000.00, $balanceSheet['equity']['total']);
        $this->assertSame('৳67,000.00', $balanceSheet['equity']['formatted_total']);

        $this->assertSame(79000.00, $balanceSheet['total_liabilities_and_equity']);
        $this->assertSame('৳79,000.00', $balanceSheet['formatted_total_liabilities_and_equity']);

        $this->assertTrue($balanceSheet['is_balanced']);
        $this->assertSame(0.00, $balanceSheet['discrepancy']);
        $this->assertSame('৳0.00', $balanceSheet['formatted_discrepancy']);
    }

    public function test_balance_sheet_filters_strictly_by_as_of_date(): void
    {
        // Entry 1: 2026-11-01 -> Cash 10,000 / Owner equity 10,000
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-01',
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: 'test_bs_date_1',
            description: 'Capital deposit Nov 1',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 10000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'owner_equity', debit: 0.00, credit: 10000.00),
            ],
        ));

        // Entry 2: 2026-11-10 -> Cash 5,000 / Commission 5,000
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-10',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_date_2',
            description: 'Commission Nov 10',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 5000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 5000.00),
            ],
        ));

        // Entry 3: 2026-11-25 -> Cash 8,000 / Commission 8,000 (after as of date)
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-25',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_date_3',
            description: 'Commission Nov 25',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 8000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 8000.00),
            ],
        ));

        // Query as of 2026-11-15 -> Should only include Nov 1 & Nov 10 entries
        $balanceSheet = $this->service->getBalanceSheet('2026-11-15');

        $this->assertSame(15000.00, $balanceSheet['assets']['total']);
        $this->assertSame(0.00, $balanceSheet['liabilities']['total']);
        $this->assertSame(10000.00, $balanceSheet['equity']['retained_earnings']);
        $this->assertSame(5000.00, $balanceSheet['equity']['current_net_income']);
        $this->assertSame(15000.00, $balanceSheet['equity']['total']);
        $this->assertSame(15000.00, $balanceSheet['total_liabilities_and_equity']);
        $this->assertTrue($balanceSheet['is_balanced']);
    }

    public function test_income_statement_defaults_to_current_month_when_dates_are_null(): void
    {
        $statement = $this->service->getIncomeStatement();

        $this->assertSame(now()->startOfMonth()->toDateString(), $statement['start_date']);
        $this->assertSame(now()->endOfMonth()->toDateString(), $statement['end_date']);
        $this->assertStringContainsString(now()->format('M'), $statement['period_label']);
    }

    public function test_balance_sheet_defaults_to_today_when_date_is_null(): void
    {
        $balanceSheet = $this->service->getBalanceSheet();

        $this->assertSame(now()->toDateString(), $balanceSheet['as_of_date']);
        $this->assertSame(now()->format('M d, Y'), $balanceSheet['formatted_as_of_date']);
    }

    public function test_balance_sheet_detects_out_of_balance_discrepancy(): void
    {
        // Force an unbalanced state directly in the DB to test the equilibrium check
        $account = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();
        $period = AccountingPeriod::create([
            'fiscal_year_id' => AccountingFiscalYear::firstOrCreate([
                'name' => 'FY 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ])->id,
            'name' => 'Nov 2026 Test',
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-UNBALANCED-TEST',
            'period_id' => $period->id,
            'entry_date' => '2026-11-05',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'unbalanced_key_1',
            'description' => 'Unbalanced entry for testing',
        ]);

        AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 5000.00,
            'credit' => 0.00,
        ]);

        $entry->update(['status' => JournalEntryStatus::POSTED]);

        $balanceSheet = $this->service->getBalanceSheet('2026-11-10');

        $this->assertFalse($balanceSheet['is_balanced']);
        $this->assertSame(5000.00, $balanceSheet['discrepancy']);
        $this->assertSame('+৳5,000.00', $balanceSheet['formatted_discrepancy']);
    }
}
