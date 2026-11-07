<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Events\Finance\RefundSettledEvent;
use App\Filament\Admin\Resources\Refunds\Pages\ListRefunds;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerRefundDisbursementJournalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected User $seller;

    protected Order $order;

    protected SubOrder $subOrder;

    protected CustomerRefundDisbursementJournalHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY 2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_closed' => false]
        );

        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => 5],
            ['name' => 'November 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'is_closed' => false]
        );

        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);
        $this->customer = User::factory()->create([
            'role_id' => $customerRole->id,
            'is_active' => true,
        ]);

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'order_number' => 'ORD-20261107-001',
            'customer_id' => $this->customer->id,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'shipping_address' => ['recipient_name' => 'Test Customer', 'phone' => '01711111111'],
            'total_amount' => 2000.00,
            'tax_amount' => 0.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $this->subOrder = SubOrder::create([
            'parent_order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => 'SUB-20261107-001',
            'fulfillment_status_id' => 1,
            'subtotal' => 1900.00,
            'shipping_amount' => 100.00,
            'commission_amount' => 200.00,
            'is_self_delivered' => false,
        ]);

        $this->handler = app(CustomerRefundDisbursementJournalHandler::class);
    }

    public function test_settle_refund_via_mfs_creates_posted_journal(): void
    {
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 500.00,
            'reason' => 'Customer requested cancellation',
            'status' => Refund::STATUS_PAID,
            'method' => 'bkash',
            'reference' => 'TRX12345678',
            'paid_at' => now(),
        ]);

        $entry = $this->handler->recordRefundDisbursed($refund);

        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->status->value ?? (string) $entry->status);
        $this->assertSame("customer_refund_disbursed_{$refund->id}", $entry->idempotency_key);
        $this->assertSame(Refund::class, $entry->reference_type);
        $this->assertSame($refund->id, $entry->reference_id);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);
        $this->assertEquals(500.00, (float) $entry->lines->sum('debit'));
        $this->assertEquals(500.00, (float) $entry->lines->sum('credit'));

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('customer_refund_payable', $debitLine->account->system_slug);
        $this->assertEquals(500.00, (float) $debitLine->debit);
        $this->assertSame($this->customer->id, $debitLine->customer_id);
        $this->assertSame($this->order->id, $debitLine->order_id);
        $this->assertSame($this->subOrder->id, $debitLine->sub_order_id);

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('gateway_clearing', $creditLine->account->system_slug);
        $this->assertEquals(500.00, (float) $creditLine->credit);
        $this->assertSame($this->order->id, $creditLine->order_id);
        $this->assertSame($this->subOrder->id, $creditLine->sub_order_id);
    }

    public function test_settle_refund_via_bank_credits_bank_operating(): void
    {
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 1200.00,
            'reason' => 'Damaged goods return',
            'status' => Refund::STATUS_PAID,
            'method' => 'bank_wire',
            'reference' => 'BANK-TRX-999',
            'paid_at' => now(),
        ]);

        $entry = $this->handler->recordRefundDisbursed($refund);

        $this->assertNotNull($entry);
        $entry->load('lines.account');

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('bank_operating', $creditLine->account->system_slug);
        $this->assertEquals(1200.00, (float) $creditLine->credit);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('customer_refund_payable', $debitLine->account->system_slug);
        $this->assertSame($this->customer->id, $debitLine->customer_id);
    }

    public function test_settle_refund_via_cash_credits_cash_on_hand(): void
    {
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 300.00,
            'reason' => 'Store return',
            'status' => Refund::STATUS_PAID,
            'method' => 'cash',
            'reference' => 'RECEIPT-001',
            'paid_at' => now(),
        ]);

        $entry = $this->handler->recordRefundDisbursed($refund);

        $this->assertNotNull($entry);
        $entry->load('lines.account');

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('cash_on_hand', $creditLine->account->system_slug);
        $this->assertEquals(300.00, (float) $creditLine->credit);
    }

    public function test_idempotency_duplicate_calls_return_existing_entry(): void
    {
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 750.00,
            'reason' => 'Duplicate test',
            'status' => Refund::STATUS_PAID,
            'method' => 'nagad',
            'reference' => 'NGD-777',
            'paid_at' => now(),
        ]);

        $entry1 = $this->handler->recordRefundDisbursed($refund);
        $entry2 = $this->handler->recordRefundDisbursed($refund);

        $this->assertNotNull($entry1);
        $this->assertNotNull($entry2);
        $this->assertSame($entry1->id, $entry2->id);

        $count = AccountingJournalEntry::where('idempotency_key', "customer_refund_disbursed_{$refund->id}")->count();
        $this->assertSame(1, $count);
    }

    public function test_unseeded_db_or_zero_amount_returns_null_safely(): void
    {
        $subOrder2 = SubOrder::create([
            'parent_order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => 'SUB-20261107-002',
            'fulfillment_status_id' => 1,
            'subtotal' => 500.00,
            'shipping_amount' => 50.00,
            'commission_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $subOrder3 = SubOrder::create([
            'parent_order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => 'SUB-20261107-003',
            'fulfillment_status_id' => 1,
            'subtotal' => 500.00,
            'shipping_amount' => 50.00,
            'commission_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $zeroRefund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 0.00,
            'reason' => 'Zero amount test',
            'status' => Refund::STATUS_PAID,
            'method' => 'bkash',
            'reference' => 'ZERO-REF',
            'paid_at' => now(),
        ]);

        $this->assertNull($this->handler->recordRefundDisbursed($zeroRefund));

        $pendingRefund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $subOrder2->id,
            'amount' => 500.00,
            'reason' => 'Pending test',
            'status' => Refund::STATUS_PENDING,
            'method' => 'bkash',
            'reference' => 'PENDING-REF',
        ]);

        $this->assertNull($this->handler->recordRefundDisbursed($pendingRefund));

        // Delete accounts to simulate unseeded DB
        AccountingJournalLine::query()->delete();
        AccountingJournalEntry::query()->delete();
        AccountingAccount::query()->delete();

        $validRefund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $subOrder3->id,
            'amount' => 500.00,
            'reason' => 'Unseeded test',
            'status' => Refund::STATUS_PAID,
            'method' => 'bkash',
            'reference' => 'UNSEEDED-REF',
            'paid_at' => now(),
        ]);

        $this->assertNull($this->handler->recordRefundDisbursed($validRefund));
    }

    public function test_refund_settled_event_automatically_triggers_double_entry_posting(): void
    {
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $this->subOrder->id,
            'amount' => 600.00,
            'reason' => 'Event test',
            'status' => Refund::STATUS_PAID,
            'method' => 'sslcommerz',
            'reference' => 'SSL-888',
            'paid_at' => now(),
        ]);

        event(new RefundSettledEvent($refund));

        $entry = AccountingJournalEntry::where('idempotency_key', "customer_refund_disbursed_{$refund->id}")->first();
        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->status->value ?? (string) $entry->status);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('customer_refund_payable', $debitLine->account->system_slug);
        $this->assertEquals(600.00, (float) $debitLine->debit);
        $this->assertSame($this->customer->id, $debitLine->customer_id);

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('gateway_clearing', $creditLine->account->system_slug);
        $this->assertEquals(600.00, (float) $creditLine->credit);
    }

    public function test_filament_admin_mark_paid_action_records_disbursement_journal_end_to_end(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => 'SUB-20261107-999',
            'fulfillment_status_id' => 1,
            'subtotal' => 850.00,
            'shipping_amount' => 50.00,
            'commission_amount' => 85.00,
            'is_self_delivered' => false,
        ]);

        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $subOrder->id,
            'amount' => 850.00,
            'reason' => 'Admin panel settlement test',
            'status' => Refund::STATUS_PENDING,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListRefunds::class)
            ->callTableAction('markPaid', $refund, [
                'method' => 'bkash',
                'reference' => 'TRX-REF-99',
            ])
            ->assertHasNoTableActionErrors();

        $refund->refresh();
        $this->assertSame(Refund::STATUS_PAID, $refund->status);
        $this->assertSame('bkash', $refund->method);
        $this->assertSame('TRX-REF-99', $refund->reference);
        $this->assertNotNull($refund->paid_at);

        $entry = AccountingJournalEntry::where('idempotency_key', "customer_refund_disbursed_{$refund->id}")->first();
        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->status->value ?? (string) $entry->status);
        $this->assertSame(Refund::class, $entry->reference_type);
        $this->assertSame($refund->id, $entry->reference_id);

        $entry->load('lines.account');
        $this->assertCount(2, $entry->lines);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertSame('customer_refund_payable', $debitLine->account->system_slug);
        $this->assertEquals(850.00, (float) $debitLine->debit);
        $this->assertSame($this->customer->id, $debitLine->customer_id);
        $this->assertSame($this->order->id, $debitLine->order_id);
        $this->assertSame($subOrder->id, $debitLine->sub_order_id);

        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertSame('gateway_clearing', $creditLine->account->system_slug);
        $this->assertEquals(850.00, (float) $creditLine->credit);
        $this->assertSame($this->order->id, $creditLine->order_id);
        $this->assertSame($subOrder->id, $creditLine->sub_order_id);
    }
}
