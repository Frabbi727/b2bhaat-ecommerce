<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Exceptions\Accounting\SettlementException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-7: money a courier or a payment gateway holds for the platform is settled into the bank, with the fees booked
 * as expenses, so `courier_cod_receivable` and `gateway_clearing` reflect what is really still owed.
 */
class SettlementServiceTest extends TestCase
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

    /**
     * Creates a COD consignment delivered by a courier; the delivery posts its `cod_delivered` journal.
     */
    private function deliveredCourierCod(string $number, float $subtotal, float $shipping, string $courier = 'Steadfast Courier'): SubOrder
    {
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => "ORD-{$number}",
            'customer_id' => User::factory()->create()->id,
            'order_status_id' => OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending'])->id,
            'payment_status_id' => PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid'])->id,
            'payment_method_id' => $codMethod->id,
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
            'courier_name' => $courier,
            'subtotal' => $subtotal,
            'commission_amount' => round($subtotal * 0.1, 2),
            'shipping_amount' => $shipping,
            'is_self_delivered' => false,
        ]);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        return $subOrder->fresh();
    }

    private function postGatewayReceipt(float $amount): void
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

    public function test_unsettled_courier_parcels_list_each_delivered_cod_parcel_with_its_amount(): void
    {
        $first = $this->deliveredCourierCod('A1', 1000.00, 60.00);
        $second = $this->deliveredCourierCod('A2', 500.00, 60.00, 'Pathao');

        $all = $this->settlements->unsettledCourierParcels();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $all->pluck('sub_order_id')->all());
        $this->assertSame(1060.0, $all->firstWhere('sub_order_id', $first->id)['amount']);

        $steadfast = $this->settlements->unsettledCourierParcels('Steadfast Courier');
        $this->assertSame([$first->id], $steadfast->pluck('sub_order_id')->all());
    }

    public function test_a_courier_remittance_clears_the_receivable_and_books_the_courier_charges(): void
    {
        $first = $this->deliveredCourierCod('B1', 1000.00, 60.00);
        $second = $this->deliveredCourierCod('B2', 500.00, 60.00);

        $entry = $this->settlements->settleCourierRemittance(
            subOrderIds: [$first->id, $second->id],
            receivedAmount: 1590.00,
            courierCharges: 30.00,
            depositAccountSlug: 'bank_operating',
            entryDate: now()->toDateString(),
            reference: 'STEADFAST-PAY-77',
            actor: $this->admin,
        );

        $this->assertSame('courier_cod_settled', $entry->financial_event);
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('courier_cod_receivable'));
        $this->assertSame(1590.0, $this->ledger->getBalanceForSlug('bank_operating'));
        $this->assertSame(30.0, $this->ledger->getBalanceForSlug('courier_delivery_expense'));

        $this->assertNotNull($first->fresh()->courier_settled_at);
        $this->assertSame($entry->id, $second->fresh()->courier_settlement_journal_id);
        $this->assertCount(0, $this->settlements->unsettledCourierParcels());
        $this->assertDatabaseHas('audit_logs', ['module' => 'accounting', 'action' => 'courier_remittance_recorded', 'auditable_id' => $entry->id]);
    }

    public function test_a_remittance_that_does_not_add_up_is_refused_and_changes_nothing(): void
    {
        $parcel = $this->deliveredCourierCod('C1', 1000.00, 60.00);

        try {
            $this->settlements->settleCourierRemittance([$parcel->id], 1000.00, 30.00, 'bank_operating', now()->toDateString(), null, $this->admin);
            $this->fail('A remittance that does not add up should be refused.');
        } catch (SettlementException $e) {
            $this->assertStringContainsString('1,060.00', $e->getMessage());
        }

        $this->assertNull($parcel->fresh()->courier_settled_at);
        $this->assertSame(1060.0, $this->ledger->getBalanceForSlug('courier_cod_receivable'));
    }

    public function test_a_parcel_cannot_be_settled_twice(): void
    {
        $parcel = $this->deliveredCourierCod('D1', 1000.00, 60.00);
        $this->settlements->settleCourierRemittance([$parcel->id], 1060.00, 0.00, 'bank_operating', now()->toDateString(), null, $this->admin);

        $this->expectException(SettlementException::class);

        $this->settlements->settleCourierRemittance([$parcel->id], 1060.00, 0.00, 'bank_operating', now()->toDateString(), null, $this->admin);
    }

    public function test_a_self_delivered_or_prepaid_parcel_cannot_be_settled_as_courier_cod(): void
    {
        $parcel = $this->deliveredCourierCod('E1', 1000.00, 60.00);
        $notCourierCod = SubOrder::create([
            'parent_order_id' => $parcel->parent_order_id,
            'seller_id' => $parcel->seller_id,
            'sub_order_number' => 'SO-E2',
            'fulfillment_status_id' => $parcel->fulfillment_status_id,
            'subtotal' => 200.00,
            'commission_amount' => 20.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => true,
        ]);

        $this->expectException(SettlementException::class);

        $this->settlements->settleCourierRemittance([$parcel->id, $notCourierCod->id], 1260.00, 0.00, 'bank_operating', now()->toDateString(), null, $this->admin);
    }

    public function test_a_gateway_transfer_clears_the_gateway_and_books_the_fee(): void
    {
        $this->postGatewayReceipt(5000.00);

        $entry = $this->settlements->settleGatewayTransfer(
            gateway: 'bkash',
            grossAmount: 4000.00,
            fee: 60.00,
            depositAccountSlug: 'bank_operating',
            entryDate: now()->toDateString(),
            reference: 'BKASH-SETTLE-9',
            actor: $this->admin,
        );

        $this->assertSame('gateway_settled', $entry->financial_event);
        $this->assertSame(1000.0, $this->ledger->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(3940.0, $this->ledger->getBalanceForSlug('bank_operating'));
        $this->assertSame(60.0, $this->ledger->getBalanceForSlug('gateway_fee_expense'));
        $this->assertSame('bkash', $entry->metadata['gateway'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['module' => 'accounting', 'action' => 'gateway_transfer_recorded', 'auditable_id' => $entry->id]);
    }

    public function test_a_gateway_transfer_larger_than_the_books_show_is_refused(): void
    {
        $this->postGatewayReceipt(1000.00);

        try {
            $this->settlements->settleGatewayTransfer('nagad', 1500.00, 0.00, 'bank_operating', now()->toDateString(), 'NG-BIG-1', $this->admin);
            $this->fail('A transfer larger than the clearing balance should be refused.');
        } catch (SettlementException $e) {
            $this->assertStringContainsString('1,000.00', $e->getMessage());
        }

        $this->assertSame(1, AccountingJournalEntry::count());
    }

    public function test_a_fee_as_large_as_the_transfer_is_refused(): void
    {
        $this->postGatewayReceipt(1000.00);

        $this->expectException(SettlementException::class);

        $this->settlements->settleGatewayTransfer('bkash', 100.00, 100.00, 'bank_operating', now()->toDateString(), 'BK-FEE-1', $this->admin);
    }

    public function test_only_bank_or_cash_can_receive_a_settlement(): void
    {
        $this->postGatewayReceipt(1000.00);

        $this->expectException(SettlementException::class);

        $this->settlements->settleGatewayTransfer('bkash', 500.00, 0.00, 'commission_revenue', now()->toDateString(), 'BK-ACC-1', $this->admin);
    }
}
