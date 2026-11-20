<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
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
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACC-7: the admin records courier remittances and gateway transfers from the journal list.
 */
class SettlementActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]));
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

        return $subOrder;
    }

    public function test_an_admin_records_a_courier_remittance(): void
    {
        $first = $this->deliveredCourierCod('F1', 1000.00, 60.00);
        $second = $this->deliveredCourierCod('F2', 500.00, 60.00);

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertActionVisible('recordCourierRemittance')
            ->callAction('recordCourierRemittance', data: [
                'courier' => 'Steadfast Courier',
                'sub_order_ids' => [(string) $first->id, (string) $second->id],
                'received_amount' => '1590',
                'courier_charges' => '30',
                'deposit_account' => 'bank_operating',
                'entry_date' => now()->format('Y-m-d'),
                'reference' => 'STEADFAST-PAY-77',
                'confirmed' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertNotNull($first->fresh()->courier_settled_at);
        $this->assertSame(0.0, app(GeneralLedgerService::class)->getBalanceForSlug('courier_cod_receivable'));
    }

    public function test_a_remittance_that_does_not_add_up_shows_the_reason_and_records_nothing(): void
    {
        $parcel = $this->deliveredCourierCod('G1', 1000.00, 60.00);

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('recordCourierRemittance', data: [
                'courier' => 'Steadfast Courier',
                'sub_order_ids' => [(string) $parcel->id],
                'received_amount' => '900',
                'courier_charges' => '30',
                'deposit_account' => 'bank_operating',
                'entry_date' => now()->format('Y-m-d'),
                'confirmed' => true,
            ])
            ->assertNotified('Remittance not recorded');

        $this->assertNull($parcel->fresh()->courier_settled_at);
        $this->assertSame(0, AccountingJournalEntry::where('financial_event', 'courier_cod_settled')->count());
    }

    public function test_an_admin_records_a_gateway_transfer(): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'gateway-receipt-1',
            description: 'bKash payment received',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 5000.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 5000.00),
            ],
        ));

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertActionVisible('recordGatewayTransfer')
            ->callAction('recordGatewayTransfer', data: [
                'gateway' => 'bkash',
                'gross_amount' => '4000',
                'fee' => '60',
                'deposit_account' => 'bank_operating',
                'entry_date' => now()->format('Y-m-d'),
                'reference' => 'BKASH-SETTLE-9',
                'confirmed' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $ledger = app(GeneralLedgerService::class);
        $this->assertSame(1000.0, $ledger->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(60.0, $ledger->getBalanceForSlug('gateway_fee_expense'));
    }

    public function test_settlements_need_the_confirmation_tick(): void
    {
        $parcel = $this->deliveredCourierCod('H1', 1000.00, 60.00);

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('recordCourierRemittance', data: [
                'courier' => 'Steadfast Courier',
                'sub_order_ids' => [(string) $parcel->id],
                'received_amount' => '1060',
                'courier_charges' => '0',
                'deposit_account' => 'bank_operating',
                'entry_date' => now()->format('Y-m-d'),
                'confirmed' => false,
            ])
            ->assertHasActionErrors(['confirmed']);

        $this->assertNull($parcel->fresh()->courier_settled_at);
    }
}
