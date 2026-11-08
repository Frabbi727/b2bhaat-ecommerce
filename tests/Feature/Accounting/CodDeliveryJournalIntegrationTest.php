<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodDeliveryJournalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026-2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 5, 'name' => 'November 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
    }

    public function test_courier_cod_delivery_posts_courier_receivable_and_escrow_journal(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        OrderStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-COD-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Chittagong'],
            'total_amount' => 500.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-COD-001-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Steadfast Courier',
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        $journal = AccountingJournalEntry::where('financial_event', 'cod_delivered')->where('reference_id', $subOrder->id)->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(500.00, $gl->getBalanceForSlug('courier_cod_receivable'));
        $this->assertSame(405.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(45.00, $gl->getBalanceForSlug('commission_revenue'));
        $this->assertSame(50.00, $gl->getBalanceForSlug('shipping_service_revenue'));
        $this->assertSame(0.00, $gl->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_self_delivered_cod_charges_seller_commission_journal(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        OrderStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-SELF-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1000.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-SELF-001-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => true,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        $journal = AccountingJournalEntry::where('financial_event', 'cod_self_delivered')->where('reference_id', $subOrder->id)->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        // Debit on seller_wallet_payable reduces liability by 100, so balance is -100
        $this->assertSame(-100.00, $gl->getBalanceForSlug('seller_wallet_payable', sellerId: $seller->id));
        $this->assertSame(100.00, $gl->getBalanceForSlug('commission_revenue'));
    }

    public function test_self_delivered_cod_with_zero_commission_does_not_post_journal(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);

        $order = Order::create([
            'order_number' => 'ORD-SELF-ZERO',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 500.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-SELF-ZERO-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'subtotal' => 500.00,
            'commission_amount' => 0.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => true,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        $journal = AccountingJournalEntry::where('financial_event', 'cod_self_delivered')->where('reference_id', $subOrder->id)->first();
        $this->assertNull($journal);
    }

    public function test_courier_cod_delivery_with_platform_discount_balances(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        // Order total = 1000 subtotal + 50 shipping - 100 discount = 950 total paid
        $order = Order::create([
            'order_number' => 'ORD-COD-DISC',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 950.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 100.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-COD-DISC-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Pathao Courier',
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        $journal = AccountingJournalEntry::where('financial_event', 'cod_delivered')->where('reference_id', $subOrder->id)->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        // Paid amount = 1000 + 50 - 100 = 950
        $this->assertSame(950.00, $gl->getBalanceForSlug('courier_cod_receivable'));
        $this->assertSame(100.00, $gl->getBalanceForSlug('promotional_discount_expense'));
        // Net earnings = 1000 - 100 = 900
        $this->assertSame(900.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(100.00, $gl->getBalanceForSlug('commission_revenue'));
        $this->assertSame(50.00, $gl->getBalanceForSlug('shipping_service_revenue'));
        $this->assertSame(0.00, $gl->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_courier_cod_delivery_is_idempotent(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-COD-IDEMP',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 500.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-COD-IDEMP-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Paperfly',
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        // Repeating status update is a no-op in CourierStatusService
        $statusService->updateStatus($subOrder, 'delivered');

        $entries = AccountingJournalEntry::where('financial_event', 'cod_delivered')->where('reference_id', $subOrder->id)->get();
        $this->assertCount(1, $entries);
    }

    public function test_prepaid_delivery_does_not_post_courier_cod_journal(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $bkashMethod = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-PREPAID-DELIV',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $bkashMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 500.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-PREPAID-DELIV-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Steadfast Courier',
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $statusService = app(CourierStatusService::class);
        $statusService->updateStatus($subOrder, 'delivered');

        $codJournal = AccountingJournalEntry::where('financial_event', 'cod_delivered')
            ->where('reference_id', $subOrder->id)
            ->first();
        $this->assertNull($codJournal);
    }
}
