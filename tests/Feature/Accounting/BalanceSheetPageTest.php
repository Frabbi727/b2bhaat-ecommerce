<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Filament\Admin\Pages\BalanceSheetPage;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
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

class BalanceSheetPageTest extends TestCase
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

    public function test_super_admin_can_access_balance_sheet_page(): void
    {
        $response = $this->get(BalanceSheetPage::getUrl());
        $response->assertSuccessful();
        $response->assertSee('Balance Sheet');

        Livewire::test(BalanceSheetPage::class)
            ->assertSuccessful()
            ->assertSee('Balance Sheet');
    }

    public function test_non_admin_cannot_access_balance_sheet_page(): void
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);
        $customer = User::factory()->create(['role_id' => $customerRole->id]);

        $this->actingAs($customer);

        $response = $this->get(BalanceSheetPage::getUrl());
        $response->assertForbidden();
    }

    public function test_balance_sheet_page_renders_kpi_cards_and_equilibrium_check(): void
    {
        $today = Carbon::now()->toDateString();

        // 1. Post Order Prepaid Received: 10,000 BDT into bank_operating, 10,000 credit seller_escrow_payable
        $this->postingService->post(new JournalEntryData(
            entryDate: $today,
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_page_post_1',
            description: 'Escrow deposit received',
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 10000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 10000.00),
            ],
        ));

        // 2. Post Revenue: 2,000 BDT commission revenue from escrow release, debit seller_escrow_payable
        $this->postingService->post(new JournalEntryData(
            entryDate: $today,
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: 'test_bs_page_post_2',
            description: 'Platform commission earned',
            lines: [
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 2000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 2000.00),
            ],
        ));

        $component = Livewire::test(BalanceSheetPage::class);

        $component->assertSuccessful();
        $component->assertSee('Total Assets');
        $component->assertSee('Total Liabilities');
        $component->assertSee('Total Equity');
        $component->assertSee('Balanced');

        // Assets: 10,000.00
        $component->assertSee('৳10,000.00');
        // Liabilities: 8,000.00 (10,000 - 2,000)
        $component->assertSee('৳8,000.00');
        // Equity: 2,000.00 (from current net income)
        $component->assertSee('৳2,000.00');

        // Check account rows
        $component->assertSee('Bank Account (Platform Operating)');
        $component->assertSee('Seller Escrow Held Payable');
        $component->assertSee('Cumulative Net Income (Unclosed)');
    }

    public function test_balance_sheet_page_as_of_presets_update_data(): void
    {
        $component = Livewire::test(BalanceSheetPage::class);

        // Default is today
        $this->assertEquals(now()->toDateString(), $component->get('asOfDate'));
        $this->assertEquals('today', $component->get('preset'));

        // Preset: month (End of Month)
        $component->call('setPreset', 'month');
        $this->assertEquals(now()->endOfMonth()->toDateString(), $component->get('asOfDate'));
        $this->assertEquals('month', $component->get('preset'));

        // Preset: last_month (End of Last Month)
        $component->call('setPreset', 'last_month');
        $this->assertEquals(now()->subMonthNoOverflow()->endOfMonth()->toDateString(), $component->get('asOfDate'));
        $this->assertEquals('last_month', $component->get('preset'));

        // Preset: quarter (End of Quarter)
        $component->call('setPreset', 'quarter');
        $this->assertEquals(now()->endOfQuarter()->toDateString(), $component->get('asOfDate'));
        $this->assertEquals('quarter', $component->get('preset'));

        // Preset: year (End of Year)
        $component->call('setPreset', 'year');
        $this->assertEquals(now()->endOfYear()->toDateString(), $component->get('asOfDate'));
        $this->assertEquals('year', $component->get('preset'));

        // Custom date modification clears preset
        $component->set('asOfDate', '2026-06-30');
        $this->assertNull($component->get('preset'));
    }

    public function test_balance_sheet_page_exports_valid_csv_stream(): void
    {
        $today = Carbon::now()->toDateString();

        $this->postingService->post(new JournalEntryData(
            entryDate: $today,
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_bs_csv_1',
            description: 'Escrow deposit',
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 5000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 5000.00),
            ],
        ));

        $component = Livewire::test(BalanceSheetPage::class);
        $response = $component->instance()->exportCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('balance-sheet-', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $csvContent = (string) ob_get_clean();

        // Assert UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);

        // Assert sections & structure
        $this->assertStringContainsString('B2Bhaat Marketplace — Balance Sheet Statement', $csvContent);
        $this->assertStringContainsString('As of Date:', $csvContent);
        $this->assertStringContainsString('CURRENT ASSETS', $csvContent);
        $this->assertStringContainsString('Total Current Assets', $csvContent);
        $this->assertStringContainsString('CURRENT LIABILITIES', $csvContent);
        $this->assertStringContainsString('Total Current Liabilities', $csvContent);
        $this->assertStringContainsString('EQUITY & CAPITAL', $csvContent);
        $this->assertStringContainsString('Cumulative Net Income (Unclosed)', $csvContent);
        $this->assertStringContainsString('Total Equity & Retained Earnings', $csvContent);
        $this->assertStringContainsString('TOTAL LIABILITIES & EQUITY', $csvContent);
        $this->assertStringContainsString('EQUILIBRIUM STATUS', $csvContent);
        $this->assertStringContainsString('BALANCED', $csvContent);
    }

    public function test_balance_sheet_page_handles_out_of_balance_state_gracefully(): void
    {
        $today = Carbon::now()->toDateString();
        $period = $this->postingService->resolveActivePeriod($today);

        // Deliberately create an unposted or direct out-of-balance line to simulate anomaly
        $entry = AccountingJournalEntry::query()->create([
            'period_id' => $period->id,
            'journal_number' => 'JE-CORRUPT-001',
            'entry_date' => $today,
            'posting_date' => now(),
            'financial_event' => AccountingEvent::MANUAL_JOURNAL->value,
            'status' => 'draft',
            'idempotency_key' => 'corrupt_test_entry',
            'description' => 'Unbalanced test entry',
            'created_by_user_id' => $this->admin->id,
        ]);

        $bankAccount = AccountingAccount::query()->where('system_slug', 'bank_operating')->firstOrFail();

        // Debit only without corresponding credit
        AccountingJournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $bankAccount->id,
            'debit' => 999.00,
            'credit' => 0.00,
            'line_order' => 1,
        ]);

        $entry->update(['status' => 'posted']);

        $component = Livewire::test(BalanceSheetPage::class);

        $component->assertSuccessful();
        $component->assertSee('Out of Balance');
        $component->assertSee('৳999.00');
    }
}
