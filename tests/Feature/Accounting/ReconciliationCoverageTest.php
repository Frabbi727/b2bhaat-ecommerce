<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Filament\Admin\Pages\FinancialReconciliationPage;
use App\Models\AuditLog;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACC-17: reconciliation also checks the money couriers and payment gateways hold for the platform, and every run
 * is kept in the audit log so finance can show when the books were checked and what was found.
 */
class ReconciliationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function deliveredCourierCod(string $number, float $subtotal, float $shipping): SubOrder
    {
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => "ORD-{$number}",
            'customer_id' => User::factory()->create()->id,
            'order_status_id' => OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending'])->id,
            'payment_status_id' => PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid'])->id,
            'payment_method_id' => PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery'])->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => $subtotal + $shipping,
            'shipping_amount' => $shipping,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => User::factory()->create()->id,
            'sub_order_number' => "SO-{$number}",
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Steadfast Courier',
            'subtotal' => $subtotal,
            'commission_amount' => round($subtotal * 0.1, 2),
            'shipping_amount' => $shipping,
            'is_self_delivered' => false,
        ]);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        return $subOrder->fresh();
    }

    private function postEntry(string $key, array $lines): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'manual_journal',
            idempotencyKey: $key,
            description: 'Test entry',
            lines: $lines,
        ));
    }

    public function test_courier_cod_receivable_matches_the_unsettled_parcels(): void
    {
        $this->deliveredCourierCod('R1', 1000.00, 60.00);

        $result = app(AccountingReconciliationService::class)->verifyCourierCodReceivable();

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame(1060.0, $result['unsettled_parcels_total']);
        $this->assertSame(1060.0, $result['ledger_balance']);
        $this->assertSame(0.0, $result['discrepancy']);
    }

    public function test_a_courier_receivable_with_no_parcel_behind_it_is_flagged(): void
    {
        $this->postEntry('stray-receivable', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 500.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 500.00),
        ]);

        $result = app(AccountingReconciliationService::class)->verifyCourierCodReceivable();

        $this->assertFalse($result['is_reconciled']);
        $this->assertSame(0.0, $result['unsettled_parcels_total']);
        $this->assertSame(500.0, $result['ledger_balance']);
        $this->assertSame(-500.0, $result['discrepancy']);
    }

    public function test_gateway_clearing_below_zero_is_flagged(): void
    {
        $service = app(AccountingReconciliationService::class);
        $this->assertTrue($service->verifyGatewayClearing()['is_reconciled']);

        $this->postEntry('gateway-overdrawn', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 300.00),
            new JournalLineData(accountSlug: 'gateway_clearing', credit: 300.00),
        ]);

        $result = $service->verifyGatewayClearing();
        $this->assertFalse($result['is_reconciled']);
        $this->assertSame(-300.0, $result['ledger_balance']);
        $this->assertSame(-300.0, $result['discrepancy']);
    }

    public function test_the_page_shows_the_new_checks_and_counts_them_in_the_overall_status(): void
    {
        $this->postEntry('stray-receivable', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 500.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 500.00),
        ]);

        $component = Livewire::test(FinancialReconciliationPage::class)
            ->assertSee('Courier COD Receivable')
            ->assertSee('Payment Gateway Clearing')
            ->assertSee('Discrepancy Detected');

        $metrics = $component->instance()->getReconciliationMetrics();
        $this->assertFalse($metrics['courier_cod']['is_reconciled']);
        $this->assertTrue($metrics['gateway_clearing']['is_reconciled']);
    }

    public function test_each_run_is_kept_in_the_audit_log(): void
    {
        Livewire::test(FinancialReconciliationPage::class)
            ->assertSet('lastAuditedAt', null)
            ->call('runAudit');

        $this->postEntry('stray-receivable', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 500.00),
            new JournalLineData(accountSlug: 'bank_operating', credit: 500.00),
        ]);

        Livewire::test(FinancialReconciliationPage::class)->call('runAudit');

        $runs = AuditLog::where('module', 'accounting')->where('action', 'reconciliation_run')->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame($this->admin->id, $runs[1]->actor_id);
        $this->assertSame(0, $runs[0]->metadata['discrepancy_count']);
        $this->assertSame(1, $runs[1]->metadata['discrepancy_count']);
        $this->assertSame(['courier_cod'], $runs[1]->metadata['failed_checks']);
        $this->assertSame(-500.0, (float) $runs[1]->metadata['checks']['courier_cod']['discrepancy']);

        // The page shows when the books were last checked, from the stored run
        Livewire::test(FinancialReconciliationPage::class)
            ->assertSet('lastAuditedAt', $runs[1]->created_at->toDateTimeString());
    }
}
