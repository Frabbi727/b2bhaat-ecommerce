<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Filament\Admin\Pages\FinancialReconciliationPage;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentFinancialReconciliationPageTest extends TestCase
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

    public function test_admin_can_access_financial_reconciliation_page(): void
    {
        $response = $this->get(FinancialReconciliationPage::getUrl());
        $response->assertSuccessful();
        $response->assertSee('Financial Reconciliation');

        Livewire::test(FinancialReconciliationPage::class)
            ->assertSuccessful()
            ->assertSee('Financial Reconciliation');
    }

    public function test_non_admin_cannot_access_financial_reconciliation_page(): void
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);
        $customer = User::factory()->create(['role_id' => $customerRole->id]);

        $this->actingAs($customer);

        $response = $this->get(FinancialReconciliationPage::getUrl());
        $response->assertForbidden();
    }

    public function test_page_renders_four_reconciliation_metric_cards(): void
    {
        Livewire::test(FinancialReconciliationPage::class)
            ->assertSuccessful()
            ->assertSee('Seller Wallet Payables')
            ->assertSee('Seller Escrow Payables')
            ->assertSee('Reserved Payout Payables')
            ->assertSee('Customer Refund Payables');
    }

    public function test_metrics_show_zero_discrepancy_when_reconciled(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        $wallet = SellerWallet::create([
            'seller_id' => $seller->id,
            'available_balance' => 1500.00,
            'escrow_held_balance' => 3000.00,
            'total_withdrawn' => 0.00,
        ]);

        // Post exact matching journal entries for the seller
        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: 'test_recon_escrow_1',
            description: 'Escrow locked',
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 3000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 3000.00, sellerId: $seller->id),
            ],
        ));

        $this->postingService->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: AccountingEvent::ESCROW_RELEASED->value,
            idempotencyKey: 'test_recon_wallet_1',
            description: 'Escrow disbursed',
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 1500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 0.00, credit: 1500.00, sellerId: $seller->id),
            ],
        ));

        $component = Livewire::test(FinancialReconciliationPage::class);
        $component->assertSuccessful();

        $metrics = $component->instance()->getReconciliationMetrics();

        $this->assertTrue($metrics['seller_wallet']['is_reconciled']);
        $this->assertEquals(0.00, $metrics['seller_wallet']['discrepancy']);
        $this->assertEquals(1500.00, $metrics['seller_wallet']['sub_ledger_total']);
        $this->assertEquals(1500.00, $metrics['seller_wallet']['gl_total']);

        $this->assertTrue($metrics['seller_escrow']['is_reconciled']);
        $this->assertEquals(0.00, $metrics['seller_escrow']['discrepancy']);
        $this->assertEquals(3000.00, $metrics['seller_escrow']['sub_ledger_total']);
        $this->assertEquals(3000.00, $metrics['seller_escrow']['gl_total']);

        $this->assertTrue($metrics['reserved_payouts']['is_reconciled']);
        $this->assertEquals(0.00, $metrics['reserved_payouts']['discrepancy']);

        $this->assertTrue($metrics['customer_refunds']['is_reconciled']);
        $this->assertEquals(0.00, $metrics['customer_refunds']['discrepancy']);
    }

    public function test_metrics_detect_discrepancy_when_subledger_and_gl_differ(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $sellerRole->id]);

        // Sub-ledger has 5000, GL has 0
        SellerWallet::create([
            'seller_id' => $seller->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 2000.00,
            'total_withdrawn' => 0.00,
        ]);

        $component = Livewire::test(FinancialReconciliationPage::class);
        $component->assertSuccessful();

        $metrics = $component->instance()->getReconciliationMetrics();

        $this->assertFalse($metrics['seller_wallet']['is_reconciled']);
        $this->assertEquals(5000.00, $metrics['seller_wallet']['discrepancy']);
        $this->assertEquals(5000.00, $metrics['seller_wallet']['sub_ledger_total']);
        $this->assertEquals(0.00, $metrics['seller_wallet']['gl_total']);

        $this->assertFalse($metrics['seller_escrow']['is_reconciled']);
        $this->assertEquals(2000.00, $metrics['seller_escrow']['discrepancy']);
    }

    public function test_admin_can_trigger_run_audit_action(): void
    {
        Livewire::test(FinancialReconciliationPage::class)
            ->call('runAudit')
            ->assertNotified('Marketplace Financial Audit Complete');
    }

    public function test_show_only_discrepancies_filter_works(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller1 = User::factory()->create(['role_id' => $sellerRole->id, 'name' => 'Seller Reconciled']);
        $seller2 = User::factory()->create(['role_id' => $sellerRole->id, 'name' => 'Seller Discrepant']);

        // Seller 1: 0 balance and 0 GL -> reconciled
        SellerWallet::create([
            'seller_id' => $seller1->id,
            'available_balance' => 0.00,
            'escrow_held_balance' => 0.00,
            'total_withdrawn' => 0.00,
        ]);

        // Seller 2: has balance without GL -> discrepancy
        SellerWallet::create([
            'seller_id' => $seller2->id,
            'available_balance' => 1200.00,
            'escrow_held_balance' => 0.00,
            'total_withdrawn' => 0.00,
        ]);

        $component = Livewire::test(FinancialReconciliationPage::class);

        // Initially showOnlyDiscrepancies is false: both sellers in table
        $component->set('showOnlyDiscrepancies', false);
        $sellersAll = $component->instance()->getSellersAuditData();
        $this->assertCount(2, $sellersAll['items']);

        // When toggled to true: only discrepant seller appears
        $component->set('showOnlyDiscrepancies', true);
        $sellersFiltered = $component->instance()->getSellersAuditData();
        $this->assertCount(1, $sellersFiltered['items']);
        $this->assertEquals($seller2->id, $sellersFiltered['items'][0]['seller_id']);
    }
}
