<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Pages\TrialBalancePage;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class FilamentTrialBalancePageTest extends TestCase
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

    public function test_the_check_names_journals_that_do_not_balance_even_when_the_totals_do(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('The PostgreSQL journal guard (ACC-9) refuses the direct edits this test plants.');
        }
        // ACC-16: two damaged journals that cancel each other out leave the grand totals equal
        $post = fn (string $key): AccountingJournalEntry => $this->postingService->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
            idempotencyKey: $key,
            description: 'Check '.$key,
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 100.00),
                new JournalLineData(accountSlug: 'owner_equity', credit: 100.00),
            ],
        ));
        $a = $post('tb-a');
        $b = $post('tb-b');
        // Direct database edits (the PostgreSQL guard would refuse these; the report must still catch them)
        DB::table('accounting_journal_lines')->where('journal_entry_id', $a->id)->where('debit', '>', 0)->update(['debit' => 110.00]);
        DB::table('accounting_journal_lines')->where('journal_entry_id', $b->id)->where('credit', '>', 0)->update(['credit' => 110.00]);

        $data = Livewire::test(TrialBalancePage::class)->instance()->getTrialBalanceData();

        $this->assertSame(0.0, $data['difference']);
        $this->assertFalse($data['is_balanced']);
        $this->assertEqualsCanonicalizing([$a->journal_number, $b->journal_number], $data['unbalanced_journals']);
    }

    public function test_admin_can_access_trial_balance_page(): void
    {
        $response = $this->get(TrialBalancePage::getUrl());
        $response->assertSuccessful();
        $response->assertSee('Trial Balance');

        Livewire::test(TrialBalancePage::class)
            ->assertSuccessful()
            ->assertSee('Trial Balance');
    }

    public function test_non_admin_cannot_access_trial_balance_page(): void
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);
        $customer = User::factory()->create(['role_id' => $customerRole->id]);

        $this->actingAs($customer);

        $response = $this->get(TrialBalancePage::getUrl());
        $response->assertForbidden();
    }

    public function test_trial_balance_renders_account_categories_and_balances(): void
    {
        $component = Livewire::test(TrialBalancePage::class);

        $component->assertSuccessful();
        $component->assertSee('Assets');
        $component->assertSee('Liabilities');
        $component->assertSee('Equity');
        $component->assertSee('Revenue');
        $component->assertSee('Expenses');

        // Cash in Hand and Bank Account should be rendered
        $component->assertSee('Cash in Hand');
        $component->assertSee('1010');
        $component->assertSee('Books Balanced');
    }

    public function test_trial_balance_reflects_posted_journal_entries(): void
    {
        $posted = $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_tb_posted_1',
            description: 'Customer payment received for Order',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 5000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 5000.00),
            ],
        ));

        // Create a draft journal entry that should NOT appear on the trial balance
        $draftEntry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-DRAFT-999',
            'period_id' => $posted->period_id,
            'entry_date' => '2026-11-07',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT,
            'financial_event' => 'draft_adjustment',
            'description' => 'Draft entry that should not count',
            'idempotency_key' => 'draft_test_tb_999',
        ]);
        $cashAccount = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();
        $revenueAccount = AccountingAccount::where('system_slug', 'commission_revenue')->firstOrFail();
        AccountingJournalLine::create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $cashAccount->id,
            'debit' => 9999.00,
            'credit' => 0.00,
        ]);
        AccountingJournalLine::create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0.00,
            'credit' => 9999.00,
        ]);

        $component = Livewire::test(TrialBalancePage::class);
        $data = $component->instance()->getTrialBalanceData();

        $cashAccountData = collect($data['categories']['asset']['accounts'])->firstWhere('system_slug', 'cash_on_hand');
        $this->assertNotNull($cashAccountData);
        $this->assertEquals(5000.00, $cashAccountData['total_debit']);
        $this->assertEquals(0.00, $cashAccountData['total_credit']);
        $this->assertEquals(5000.00, $cashAccountData['net_debit']);

        $revenueAccountData = collect($data['categories']['revenue']['accounts'])->firstWhere('system_slug', 'commission_revenue');
        $this->assertNotNull($revenueAccountData);
        $this->assertEquals(5000.00, $revenueAccountData['total_credit']);
        $this->assertEquals(0.00, $revenueAccountData['total_debit']);
        $this->assertEquals(5000.00, $revenueAccountData['net_credit']);

        // Assert draft amounts (9999.00) are excluded
        $component->assertDontSee('9,999.00');
    }

    public function test_trial_balance_balances_debits_and_credits_accurately(): void
    {
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-01',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_tb_multi_1',
            description: 'Prepaid order',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 12000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 12000.00),
            ],
        ));

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-02',
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: 'test_tb_multi_2',
            description: 'Escrow release with fee',
            lines: [
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 12000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 0.00, credit: 11000.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 1000.00),
            ],
        ));

        $component = Livewire::test(TrialBalancePage::class);
        $data = $component->instance()->getTrialBalanceData();

        $this->assertTrue($data['is_balanced']);
        $this->assertEquals(0.00, $data['difference']);
        $this->assertEquals($data['total_debit'], $data['total_credit']);
        $this->assertEquals($data['total_net_debit'], $data['total_net_credit']);

        $component->assertSee('Books Balanced: Total Debits equal Total Credits');
    }

    public function test_trial_balance_filters_by_date_range(): void
    {
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-01-15',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'tb_date_1',
            description: 'January entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 1000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 1000.00),
            ],
        ));

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-02-15',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'tb_date_2',
            description: 'February entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 2000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 2000.00),
            ],
        ));

        $component = Livewire::test(TrialBalancePage::class)
            ->set('startDate', '2026-02-01')
            ->set('endDate', '2026-02-28');

        $data = $component->instance()->getTrialBalanceData();
        $cashData = collect($data['categories']['asset']['accounts'])->firstWhere('system_slug', 'cash_on_hand');

        $this->assertEquals(2000.00, $cashData['total_debit']);
        $this->assertEquals(2000.00, $data['total_debit']);
        $this->assertEquals(2000.00, $data['total_credit']);
    }

    public function test_trial_balance_filters_by_period(): void
    {
        $fiscalYear = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY 2026'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_closed' => false]
        );

        $period1 = AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id, 'period_number' => 1],
            ['name' => 'P1 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'is_closed' => false]
        );

        $period2 = AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id, 'period_number' => 2],
            ['name' => 'P2 2026', 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'is_closed' => false]
        );

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-01-10',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'tb_period_1',
            description: 'Period 1 entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 1500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 1500.00),
            ],
        ));

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-02-10',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'tb_period_2',
            description: 'Period 2 entry',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 3500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 3500.00),
            ],
        ));

        $component1 = Livewire::test(TrialBalancePage::class)
            ->set('periodId', $period1->id);

        $data1 = $component1->instance()->getTrialBalanceData();
        $cash1 = collect($data1['categories']['asset']['accounts'])->firstWhere('system_slug', 'cash_on_hand');
        $this->assertEquals(1500.00, $cash1['total_debit']);

        $component2 = Livewire::test(TrialBalancePage::class)
            ->set('periodId', $period2->id);

        $data2 = $component2->instance()->getTrialBalanceData();
        $cash2 = collect($data2['categories']['asset']['accounts'])->firstWhere('system_slug', 'cash_on_hand');
        $this->assertEquals(3500.00, $cash2['total_debit']);
    }

    public function test_hide_zero_balances_toggle_filters_empty_accounts(): void
    {
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'tb_zero_1',
            description: 'One active transaction',
            lines: [
                new JournalLineData(accountSlug: 'cash_on_hand', debit: 450.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 450.00),
            ],
        ));

        // When hideZeroBalances is false, seeded empty accounts like bank_operating (1020) should be included
        $componentWithZero = Livewire::test(TrialBalancePage::class)
            ->set('hideZeroBalances', false);
        $dataWithZero = $componentWithZero->instance()->getTrialBalanceData();
        $bankAccount = collect($dataWithZero['categories']['asset']['accounts'])->firstWhere('system_slug', 'bank_operating');
        $this->assertNotNull($bankAccount);
        $this->assertEquals(0.00, $bankAccount['total_debit']);

        // When hideZeroBalances is true, bank_operating (1020) should be excluded
        $componentWithoutZero = Livewire::test(TrialBalancePage::class)
            ->set('hideZeroBalances', true);
        $dataWithoutZero = $componentWithoutZero->instance()->getTrialBalanceData();
        $bankAccountHidden = collect($dataWithoutZero['categories']['asset']['accounts'])->firstWhere('system_slug', 'bank_operating');
        $this->assertNull($bankAccountHidden);

        // Active accounts Cash and Commission Revenue should still be present
        $cashPresent = collect($dataWithoutZero['categories']['asset']['accounts'])->firstWhere('system_slug', 'cash_on_hand');
        $this->assertNotNull($cashPresent);
        $this->assertEquals(450.00, $cashPresent['total_debit']);
    }

    public function test_admin_can_export_trial_balance_csv(): void
    {
        $component = Livewire::test(TrialBalancePage::class);
        $response = $component->instance()->exportCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('trial-balance-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_admin_can_use_date_presets_on_trial_balance(): void
    {
        $component = Livewire::test(TrialBalancePage::class)
            ->call('setPreset', 'month');

        $this->assertEquals(now()->startOfMonth()->toDateString(), $component->get('startDate'));
        $this->assertEquals(now()->endOfMonth()->toDateString(), $component->get('endDate'));
        $this->assertEquals('month', $component->get('preset'));

        $component->call('setPreset', 'all');
        $this->assertNull($component->get('startDate'));
        $this->assertNull($component->get('endDate'));
        $this->assertEquals('all', $component->get('preset'));
    }
}
