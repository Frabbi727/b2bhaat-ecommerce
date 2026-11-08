<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\CodDeliveryJournalHandler;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Escrow\EscrowLedgerService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowAndRefundJournalIntegrationTest extends TestCase
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

    public function test_escrow_release_transitions_liability_to_available_wallet(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'COD']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $delivered = FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-ESC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Sylhet'],
            'total_amount' => 1000.00,
            'shipping_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-ESC-001-A',
            'fulfillment_status_id' => $delivered->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 0.00,
        ]);

        // Establish initial COD delivery journal hold
        app(CodDeliveryJournalHandler::class)->recordCourierCodDelivery($subOrder);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);

        $escrowService->releaseFromEscrow($subOrder);

        $journal = AccountingJournalEntry::where('financial_event', 'escrow_released')
            ->where('reference_id', $subOrder->id)
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        // Escrow held balance reduced to 0, available wallet liability is now 900
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(900.00, $gl->getBalanceForSlug('seller_wallet_payable', sellerId: $seller->id));
    }

    public function test_cancelling_held_escrow_reverses_and_records_customer_refund_payable(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $order = Order::create([
            'order_number' => 'ORD-CAN-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Rajshahi'],
            'total_amount' => 1100.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-CAN-001-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // Establish initial prepaid payment journal hold
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);

        $escrowService->refundCancelledEscrow($subOrder, 'Customer cancelled before dispatch');

        $journal = AccountingJournalEntry::where('financial_event', 'refund_processed')
            ->where('reference_id', $subOrder->id)
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(1100.00, $gl->getBalanceForSlug('customer_refund_payable'));
    }

    public function test_returned_suborder_after_release_reverses_wallet_and_records_customer_refund(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $delivered = FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-RET-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Barisal'],
            'total_amount' => 1100.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-RET-001-A',
            'fulfillment_status_id' => $delivered->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);
        $escrowService->releaseFromEscrow($subOrder);

        $escrowService->reverseReturnedSubOrder($subOrder, 'Product defective - buyer returned');

        $journal = AccountingJournalEntry::where('financial_event', 'refund_processed')
            ->where('reference_id', $subOrder->id)
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        // Released wallet balance clawed back to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_wallet_payable', sellerId: $seller->id));
        // Customer refund payable recorded for total paid amount
        $this->assertSame(1100.00, $gl->getBalanceForSlug('customer_refund_payable'));
    }

    public function test_self_delivered_cod_return_reverses_commission_charge(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'COD']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $delivered = FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-SELF-RET-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1050.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-SELF-RET-001-A',
            'fulfillment_status_id' => $delivered->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => true,
        ]);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->settleSelfCollectedCod($subOrder);
        app(CodDeliveryJournalHandler::class)->recordSelfDeliveredCod($subOrder);

        $escrowService->reverseReturnedSubOrder($subOrder, 'Self-delivered customer refusal');

        $journal = AccountingJournalEntry::where('financial_event', 'refund_processed')
            ->where('reference_id', $subOrder->id)
            ->first();
        $this->assertNotNull($journal);

        $gl = app(GeneralLedgerService::class);
        // Commission revenue reversed back to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('commission_revenue'));
        // Seller wallet payable restored back to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_wallet_payable', sellerId: $seller->id));
    }

    public function test_cancelling_discounted_prepaid_suborder_reverses_discount_expense_and_balances(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        // Subtotal: 1000, Shipping: 100, Discount: 200, Total Amount: 900
        $order = Order::create([
            'order_number' => 'ORD-DISC-CAN-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Khulna'],
            'total_amount' => 900.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 200.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-DISC-CAN-001-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);

        $escrowService->refundCancelledEscrow($subOrder, 'Customer cancelled with voucher coupon');

        $gl = app(GeneralLedgerService::class);
        // Escrow held balance reduced to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        // Promotional discount expense reversed from 200 down to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('promotional_discount_expense'));
        // Commission revenue reversed down to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('commission_revenue'));
        // Shipping revenue reversed down to 0
        $this->assertSame(0.00, $gl->getBalanceForSlug('shipping_service_revenue'));
        // Customer refund payable equals exactly what the customer paid: 900
        $this->assertSame(900.00, $gl->getBalanceForSlug('customer_refund_payable'));
    }

    public function test_escrow_release_is_idempotent_and_does_not_duplicate_journal_entries(): void
    {
        $seller = User::factory()->create();
        $customer = User::factory()->create();
        $method = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'COD']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $delivered = FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-IDEM-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $method->id,
            'shipping_address' => ['address' => 'Comilla'],
            'total_amount' => 500.00,
            'shipping_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-IDEM-001-A',
            'fulfillment_status_id' => $delivered->id,
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
            'shipping_amount' => 0.00,
        ]);

        app(CodDeliveryJournalHandler::class)->recordCourierCodDelivery($subOrder);

        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);

        $escrowService->releaseFromEscrow($subOrder);
        $escrowService->releaseFromEscrow($subOrder);

        $entriesCount = AccountingJournalEntry::where('financial_event', 'escrow_released')
            ->where('reference_id', $subOrder->id)
            ->count();

        $this->assertSame(1, $entriesCount);
    }
}
