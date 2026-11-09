<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\SettlementException;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ListAccountingJournalEntries;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SettlementService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wave 1 review I-1: a gateway transfer can't be recorded twice, and a courier remittance or gateway transfer
 * recorded by mistake can be undone (reversal journal, parcels back on the unsettled list).
 */
class SettlementSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SettlementService $settlements;

    private GeneralLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]);
        $this->settlements = app(SettlementService::class);
        $this->ledger = app(GeneralLedgerService::class);
    }

    private function gatewayReceipt(float $amount): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'gateway-receipt-'.uniqid(),
            description: 'bKash payment received',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: $amount),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: $amount),
            ],
        ));
    }

    private function deliveredCourierCod(string $number): SubOrder
    {
        $order = Order::create([
            'order_number' => "ORD-{$number}",
            'customer_id' => User::factory()->create()->id,
            'order_status_id' => OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending'])->id,
            'payment_status_id' => PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid'])->id,
            'payment_method_id' => PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery'])->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1060.00,
            'shipping_amount' => 60.00,
            'discount_amount' => 0.00,
        ]);
        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => User::factory()->create()->id,
            'sub_order_number' => "SO-{$number}",
            'fulfillment_status_id' => FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery'])->id,
            'courier_name' => 'Steadfast Courier',
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 60.00,
            'is_self_delivered' => false,
        ]);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);
        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        return $subOrder->fresh();
    }

    public function test_the_same_gateway_transfer_reference_cannot_be_recorded_twice(): void
    {
        $this->gatewayReceipt(5000.00);
        $first = $this->settlements->settleGatewayTransfer('bkash', 1000.00, 10.00, 'bank_operating', now()->toDateString(), 'BK-SETTLE-77', $this->admin);

        try {
            // Same reference typed differently: lower case and a space
            $this->settlements->settleGatewayTransfer('bkash', 1000.00, 10.00, 'bank_operating', now()->toDateString(), 'bk-settle 77', $this->admin);
            $this->fail('A repeated transfer reference must be refused.');
        } catch (SettlementException $e) {
            $this->assertStringContainsString($first->journal_number, $e->getMessage());
        }

        $this->assertSame(4000.0, $this->ledger->getBalanceForSlug('gateway_clearing'));
        // The same reference on another gateway is a different transfer
        $this->settlements->settleGatewayTransfer('nagad', 500.00, 0.00, 'bank_operating', now()->toDateString(), 'BK-SETTLE-77', $this->admin);
    }

    public function test_a_gateway_transfer_needs_a_reference(): void
    {
        $this->gatewayReceipt(5000.00);

        $this->expectException(SettlementException::class);
        $this->expectExceptionMessage('reference');

        $this->settlements->settleGatewayTransfer('bkash', 1000.00, 0.00, 'bank_operating', now()->toDateString(), '  ', $this->admin);
    }

    public function test_undoing_a_gateway_transfer_restores_the_balances_and_frees_its_reference(): void
    {
        $this->gatewayReceipt(5000.00);
        $entry = $this->settlements->settleGatewayTransfer('bkash', 1000.00, 10.00, 'bank_operating', now()->toDateString(), 'BK-1', $this->admin);

        $reversal = $this->settlements->undoSettlement($entry, 'Wrong amount', $this->admin);

        $this->assertSame('reversal', $reversal->financial_event);
        $this->assertSame(JournalEntryStatus::REVERSED, $entry->fresh()->status);
        $this->assertSame(5000.0, $this->ledger->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('bank_operating'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('gateway_fee_expense'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement_undone', 'auditable_id' => $entry->id]);

        // The corrected transfer can now be recorded with the same reference
        $this->settlements->settleGatewayTransfer('bkash', 1100.00, 10.00, 'bank_operating', now()->toDateString(), 'BK-1', $this->admin);
        $this->assertSame(3900.0, $this->ledger->getBalanceForSlug('gateway_clearing'));
    }

    public function test_undoing_a_courier_remittance_puts_its_parcels_back_on_the_unsettled_list(): void
    {
        $parcel = $this->deliveredCourierCod('U1');
        $entry = $this->settlements->settleCourierRemittance([$parcel->id], 1030.00, 30.00, 'bank_operating', now()->toDateString(), 'SF-9', $this->admin);

        $this->settlements->undoSettlement($entry, 'Wrong parcel', $this->admin);

        $parcel->refresh();
        $this->assertNull($parcel->courier_settled_at);
        $this->assertNull($parcel->courier_settlement_journal_id);
        $this->assertSame([$parcel->id], $this->settlements->unsettledCourierParcels()->pluck('sub_order_id')->all());
        $this->assertSame(1060.0, $this->ledger->getBalanceForSlug('courier_cod_receivable'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('courier_delivery_expense'));

        // Undoing twice changes nothing more
        $this->settlements->undoSettlement($entry->fresh(), 'Again', $this->admin);
        $this->assertSame(1060.0, $this->ledger->getBalanceForSlug('courier_cod_receivable'));
    }

    public function test_only_settlement_journals_can_be_undone_this_way(): void
    {
        $this->gatewayReceipt(100.00);
        $other = AccountingJournalEntry::sole();

        $this->expectException(SettlementException::class);

        $this->settlements->undoSettlement($other, 'Not a settlement', $this->admin);
    }

    public function test_an_admin_undoes_a_settlement_from_the_journal_list(): void
    {
        $this->gatewayReceipt(5000.00);
        $entry = $this->settlements->settleGatewayTransfer('bkash', 1000.00, 0.00, 'bank_operating', now()->toDateString(), 'BK-UI', $this->admin);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertActionHidden(TestAction::make('reverse')->table($entry))
            ->callAction(TestAction::make('undoSettlement')->table($entry), data: ['reason' => 'Entered twice'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(JournalEntryStatus::REVERSED, $entry->fresh()->status);
        $this->assertSame(5000.0, $this->ledger->getBalanceForSlug('gateway_clearing'));
    }
}
