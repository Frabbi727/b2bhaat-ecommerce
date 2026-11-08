<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Filament\Admin\Pages\IncomeStatementPage;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\Carbon;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class IncomeStatementPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private JournalPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->postingService = app(JournalPostingService::class);
    }

    public function test_super_admin_can_access_income_statement_page(): void
    {
        $response = $this->get(IncomeStatementPage::getUrl());
        $response->assertSuccessful();
        $response->assertSee('Income Statement');

        Livewire::test(IncomeStatementPage::class)
            ->assertSuccessful()
            ->assertSee('Income Statement');
    }

    public function test_non_admin_cannot_access_income_statement_page(): void
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);
        $customer = User::factory()->create(['role_id' => $customerRole->id]);

        $this->actingAs($customer);

        $response = $this->get(IncomeStatementPage::getUrl());
        $response->assertForbidden();
    }

    public function test_income_statement_page_renders_kpi_cards_and_statement_table(): void
    {
        $currentMonthDate = Carbon::now()->startOfMonth()->addDays(2)->toDateString();

        // 1. Post Revenue: 2,000 BDT
        $this->postingService->post(new JournalEntryData(
            entryDate: $currentMonthDate,
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: 'test_inc_page_rev_1',
            description: 'Platform commission fee',
            lines: [
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 2000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 2000.00),
            ],
        ));

        // 2. Post Expense: 500 BDT
        $this->postingService->post(new JournalEntryData(
            entryDate: $currentMonthDate,
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_inc_page_exp_1',
            description: 'Gateway processing fee',
            lines: [
                new JournalLineData(accountSlug: 'gateway_fee_expense', debit: 500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 500.00),
            ],
        ));

        $component = Livewire::test(IncomeStatementPage::class);

        $component->assertSuccessful();
        $component->assertSee('Total Revenue');
        $component->assertSee('Total Expenses');
        $component->assertSee('Net Profit');
        $component->assertSee('Operating Profit Margin');

        // Verify pre-calculated values appear
        $component->assertSee('৳2,000.00');
        $component->assertSee('৳500.00');
        $component->assertSee('+৳1,500.00');
        $component->assertSee('75.00%');

        // Verify account rows
        $component->assertSee('Marketplace Commission Revenue');
        $component->assertSee('Payment Gateway Processing Fees');
        $component->assertSee('4010');
        $component->assertSee('5020');
    }

    public function test_income_statement_page_renders_net_loss_state_when_expenses_exceed_revenues(): void
    {
        $this->actingAs($this->admin);

        // Post expense greater than revenue
        $this->postingService->post(new JournalEntryData(
            entryDate: Carbon::now()->startOfMonth()->addDays(2)->toDateString(),
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_inc_page_loss_1',
            description: 'Large Gateway Expense',
            lines: [
                new JournalLineData(accountSlug: 'gateway_fee_expense', debit: 3000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'bank_operating', debit: 0.00, credit: 3000.00),
            ],
        ));

        $component = Livewire::test(IncomeStatementPage::class);

        $component->assertSuccessful();
        $component->assertSee('Net Loss');
        $component->assertSee('-৳3,000.00');
        $component->assertSee('0.00%');
    }

    public function test_income_statement_page_presets_update_date_boundaries(): void
    {
        $component = Livewire::test(IncomeStatementPage::class);

        // Default is month
        $this->assertEquals(now()->startOfMonth()->toDateString(), $component->get('startDate'));
        $this->assertEquals(now()->endOfMonth()->toDateString(), $component->get('endDate'));
        $this->assertEquals('month', $component->get('preset'));

        // Preset: last_month
        $component->call('setPreset', 'last_month');
        $this->assertEquals(now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $component->get('startDate'));
        $this->assertEquals(now()->subMonthNoOverflow()->endOfMonth()->toDateString(), $component->get('endDate'));
        $this->assertEquals('last_month', $component->get('preset'));

        // Preset: quarter
        $component->call('setPreset', 'quarter');
        $this->assertEquals(now()->startOfQuarter()->toDateString(), $component->get('startDate'));
        $this->assertEquals(now()->endOfQuarter()->toDateString(), $component->get('endDate'));
        $this->assertEquals('quarter', $component->get('preset'));

        // Preset: year
        $component->call('setPreset', 'year');
        $this->assertEquals(now()->startOfYear()->toDateString(), $component->get('startDate'));
        $this->assertEquals(now()->endOfYear()->toDateString(), $component->get('endDate'));
        $this->assertEquals('year', $component->get('preset'));

        // Preset: all
        $component->call('setPreset', 'all');
        $this->assertNull($component->get('startDate'));
        $this->assertNull($component->get('endDate'));
        $this->assertEquals('all', $component->get('preset'));

        // Custom date modification clears preset
        $component->set('startDate', '2026-01-01');
        $this->assertNull($component->get('preset'));

        $component->set('preset', 'month');
        $component->set('endDate', '2026-12-31');
        $this->assertNull($component->get('preset'));
    }

    public function test_income_statement_page_exports_valid_csv_stream(): void
    {
        $currentMonthDate = Carbon::now()->startOfMonth()->addDays(1)->toDateString();

        $this->postingService->post(new JournalEntryData(
            entryDate: $currentMonthDate,
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: 'test_csv_inc_1',
            description: 'Platform commission fee',
            lines: [
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 1000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 1000.00),
            ],
        ));

        $component = Livewire::test(IncomeStatementPage::class);
        $response = $component->instance()->exportCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('income-statement-', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $csvContent = (string) ob_get_clean();

        // Assert UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);

        // Assert headers & structure
        $this->assertStringContainsString('B2Bhaat Marketplace — Income Statement (Profit & Loss)', $csvContent);
        $this->assertStringContainsString('OPERATING REVENUE', $csvContent);
        $this->assertStringContainsString('OPERATING EXPENSES', $csvContent);
        $this->assertStringContainsString('Total Operating Revenue', $csvContent);
        $this->assertStringContainsString('Total Operating Expenses', $csvContent);
        $this->assertStringContainsString('NET OPERATING INCOME / (LOSS)', $csvContent);
        $this->assertStringContainsString('Operating Margin %', $csvContent);
        $this->assertStringContainsString('Commission Revenue', $csvContent);
        $this->assertStringContainsString('4010', $csvContent);
    }
}
