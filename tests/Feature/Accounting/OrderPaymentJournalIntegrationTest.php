<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Payments\PaymentVerificationService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentJournalIntegrationTest extends TestCase
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

    public function test_marking_payment_verified_posts_balanced_double_entry_journal(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        $order = Order::create([
            'order_number' => 'ORD-TEST-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1000.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-TEST-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 900.00,
            'commission_amount' => 90.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        $service = app(PaymentVerificationService::class);
        $service->markVerified($order);

        $journal = AccountingJournalEntry::where('reference_id', $order->id)
            ->where('financial_event', 'order_prepaid_received')
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(1000.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(810.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(90.00, $gl->getBalanceForSlug('commission_revenue'));
        $this->assertSame(-100.00, $gl->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_payment_verification_with_platform_discount_balances(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        // 1000 subtotal, 100 discount, 900 total paid
        $order = Order::create([
            'order_number' => 'ORD-DISC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 900.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 100.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-DISC-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => false,
        ]);

        $service = app(PaymentVerificationService::class);
        $service->markVerified($order);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(900.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(100.00, $gl->getBalanceForSlug('promotional_discount_expense'));
        $this->assertSame(900.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(100.00, $gl->getBalanceForSlug('commission_revenue'));
    }

    public function test_payment_verification_with_self_delivered_order_credits_shipping_to_seller_escrow(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        $order = Order::create([
            'order_number' => 'ORD-SELF-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1000.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-SELF-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 900.00,
            'commission_amount' => 90.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => true,
        ]);

        $service = app(PaymentVerificationService::class);
        $service->markVerified($order);

        $journal = AccountingJournalEntry::where('reference_id', $order->id)
            ->where('financial_event', 'order_prepaid_received')
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(1000.00, $gl->getBalanceForSlug('gateway_clearing'));
        // 810 net earnings + 100 self-delivered shipping = 910
        $this->assertSame(910.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(90.00, $gl->getBalanceForSlug('commission_revenue'));
        $this->assertSame(0.00, $gl->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_multi_vendor_order_posts_balanced_journal_across_multiple_sellers(): void
    {
        $sellerA = User::factory()->create();
        $sellerB = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        // Order total: 2000 (SubOrder A: 1000 + 100 shipping; SubOrder B: 800 + 100 shipping)
        $order = Order::create([
            'order_number' => 'ORD-MV-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 2000.00,
            'shipping_amount' => 200.00,
            'discount_amount' => 0.00,
        ]);

        // Seller A: subtotal 1000, commission 100, shipping 100 (courier), net 900
        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'sub_order_number' => 'ORD-MV-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // Seller B: subtotal 800, commission 80, shipping 100 (self-delivered), net 720 + 100 shipping = 820
        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $sellerB->id,
            'sub_order_number' => 'ORD-MV-001-B',
            'fulfillment_status_id' => 1,
            'subtotal' => 800.00,
            'commission_amount' => 80.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => true,
        ]);

        $service = app(PaymentVerificationService::class);
        $service->markVerified($order);

        $journal = AccountingJournalEntry::where('reference_id', $order->id)
            ->where('financial_event', 'order_prepaid_received')
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(2000.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(900.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $sellerA->id));
        $this->assertSame(820.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $sellerB->id));
        $this->assertSame(180.00, $gl->getBalanceForSlug('commission_revenue'));
        $this->assertSame(-100.00, $gl->getBalanceForSlug('courier_delivery_expense'));
    }

    public function test_record_prepaid_payment_is_idempotent(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        $order = Order::create([
            'order_number' => 'ORD-IDEMP-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 500.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-IDEMP-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        $handler = app(OrderPaymentJournalHandler::class);
        $entry1 = $handler->recordPrepaidPayment($order);
        $entry2 = $handler->recordPrepaidPayment($order);

        $this->assertSame($entry1->id, $entry2->id);
        $this->assertSame(1, AccountingJournalEntry::where('idempotency_key', "order_prepaid_received_{$order->id}")->count());
    }

    public function test_100_percent_promotional_discount_order_balances_without_gateway_clearing_line(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'coupon'], ['name' => 'Free / Coupon']);
        $awaiting = PaymentStatus::firstOrCreate(['slug' => 'awaiting_verification'], ['name' => 'Awaiting Verification']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);

        // 500 subtotal, 500 discount -> total_amount = 0.00
        $order = Order::create([
            'order_number' => 'ORD-FREE-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $awaiting->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 0.00,
            'shipping_amount' => 0.00,
            'discount_amount' => 500.00,
        ]);

        SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-FREE-001-A',
            'fulfillment_status_id' => 1,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 0.00,
            'is_self_delivered' => false,
        ]);

        $service = app(PaymentVerificationService::class);
        $service->markVerified($order);

        $journal = AccountingJournalEntry::where('reference_id', $order->id)
            ->where('financial_event', 'order_prepaid_received')
            ->first();
        $this->assertNotNull($journal);

        // Verify gateway_clearing has no lines (balance 0.00)
        $gl = app(GeneralLedgerService::class);
        $this->assertSame(0.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(500.00, $gl->getBalanceForSlug('promotional_discount_expense'));
        $this->assertSame(450.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(50.00, $gl->getBalanceForSlug('commission_revenue'));
    }
}
