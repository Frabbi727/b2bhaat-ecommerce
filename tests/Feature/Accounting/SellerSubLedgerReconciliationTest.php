<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingPeriod;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerSubLedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected AccountingReconciliationService $reconciliationService;

    protected GeneralLedgerService $gl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY 2026-2027'],
            ['start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'is_closed' => false]
        );

        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n')],
            [
                'name' => now()->format('F Y'),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'is_closed' => false,
            ]
        );

        $this->reconciliationService = app(AccountingReconciliationService::class);
        $this->gl = app(GeneralLedgerService::class);
    }

    protected function createSeller(string $name = 'Test Factory Seller'): User
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);

        return User::factory()->create([
            'role_id' => $sellerRole->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function createCustomer(string $name = 'Test Retail Customer'): User
    {
        $customerRole = Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer']);

        return User::factory()->create([
            'role_id' => $customerRole->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Full COD order delivery -> Escrow release -> verifySellerWallet has 0.00 discrepancy and is_reconciled = true.
     */
    public function test_full_cod_order_delivery_and_escrow_release_reconciles_cleanly(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        OrderStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-COD-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => 1000.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-COD-REC-001-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'courier_name' => 'Steadfast Courier',
            'subtotal' => 900.00,
            'commission_amount' => 90.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // 1. Deliver via CourierStatusService: marks delivered, triggers COD journal and escrow hold
        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        // Net earnings = 900 - 90 = 810.00 held in escrow
        $midVerification = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($midVerification['is_reconciled']);
        $this->assertSame(0.00, $midVerification['wallet_balance']);
        $this->assertSame(0.00, $midVerification['ledger_balance']);
        $this->assertSame(810.00, $midVerification['escrow_wallet_balance']);
        $this->assertSame(810.00, $midVerification['escrow_ledger_balance']);

        // 2. Release from escrow: transfers liability from seller_escrow_payable to seller_wallet_payable
        app(EscrowLedgerService::class)->releaseFromEscrow($subOrder);

        // 3. Verify final sub-ledger reconciliation
        $result = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame(810.00, $result['wallet_balance']);
        $this->assertSame(810.00, $result['ledger_balance']);
        $this->assertSame(0.00, $result['discrepancy']);
        $this->assertSame(0.00, $result['escrow_wallet_balance']);
        $this->assertSame(0.00, $result['escrow_ledger_balance']);
        $this->assertSame(0.00, $result['escrow_discrepancy']);
        $this->assertSame($seller->id, $result['seller_id']);
    }

    /**
     * Test 2: Prepaid order -> Delivery -> Escrow release -> verifySellerWallet has 0.00 discrepancy.
     */
    public function test_prepaid_order_delivery_and_escrow_release_reconciles_cleanly(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $bkashMethod = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        OrderStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-PRE-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $bkashMethod->id,
            'shipping_address' => ['address' => 'Chittagong'],
            'total_amount' => 1500.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-PRE-REC-001-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'subtotal' => 1400.00,
            'commission_amount' => 140.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // Prepaid payment journal records liability to seller_escrow_payable (1400 - 140 = 1260.00)
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        // Transition through delivery
        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        // Escrow Release
        app(EscrowLedgerService::class)->releaseFromEscrow($subOrder);

        $result = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame(1260.00, $result['wallet_balance']);
        $this->assertSame(1260.00, $result['ledger_balance']);
        $this->assertSame(0.00, $result['discrepancy']);
        $this->assertSame(0.00, $result['escrow_wallet_balance']);
        $this->assertSame(0.00, $result['escrow_ledger_balance']);
        $this->assertSame(0.00, $result['escrow_discrepancy']);
        $this->assertSame($seller->id, $result['seller_id']);
    }

    /**
     * Test 3: Unreleased escrow state -> escrow_held_balance matches seller_escrow_payable ledger balance, available_balance matches seller_wallet_payable (0.00), is_reconciled = true.
     */
    public function test_unreleased_escrow_state_reconciles_with_zero_available_balance(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $nagadMethod = PaymentMethod::firstOrCreate(['slug' => 'nagad'], ['name' => 'Nagad']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $processing = FulfillmentStatus::firstOrCreate(['slug' => 'processing'], ['name' => 'Processing']);

        $order = Order::create([
            'order_number' => 'ORD-HLD-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $nagadMethod->id,
            'shipping_address' => ['address' => 'Khulna'],
            'total_amount' => 2000.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-HLD-REC-001-A',
            'fulfillment_status_id' => $processing->id,
            'subtotal' => 1900.00,
            'commission_amount' => 190.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        // Net earnings = 1900 - 190 = 1710.00
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        $result = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame(0.00, $result['wallet_balance']);
        $this->assertSame(0.00, $result['ledger_balance']);
        $this->assertSame(0.00, $result['discrepancy']);
        $this->assertSame(1710.00, $result['escrow_wallet_balance']);
        $this->assertSame(1710.00, $result['escrow_ledger_balance']);
        $this->assertSame(0.00, $result['escrow_discrepancy']);
        $this->assertSame($seller->id, $result['seller_id']);
    }

    /**
     * Test 4: Refund/cancellation reversal -> wallet and ledger update in lockstep, 0.00 discrepancy.
     */
    public function test_refund_and_cancellation_reversals_maintain_lockstep_reconciliation(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        $bkashMethod = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $unfulfilled = FulfillmentStatus::firstOrCreate(['slug' => 'unfulfilled'], ['name' => 'Unfulfilled']);

        $order = Order::create([
            'order_number' => 'ORD-REF-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $paid->id,
            'payment_method_id' => $bkashMethod->id,
            'shipping_address' => ['address' => 'Rajshahi'],
            'total_amount' => 1000.00,
            'shipping_amount' => 50.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-REF-REC-001-A',
            'fulfillment_status_id' => $unfulfilled->id,
            'subtotal' => 950.00,
            'commission_amount' => 95.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        // Held in escrow = 950 - 95 = 855.00
        $beforeRefund = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($beforeRefund['is_reconciled']);
        $this->assertSame(855.00, $beforeRefund['escrow_wallet_balance']);
        $this->assertSame(855.00, $beforeRefund['escrow_ledger_balance']);

        // Cancel and refund held escrow
        app(EscrowLedgerService::class)->refundCancelledEscrow($subOrder, 'Buyer cancelled consignment before dispatch');

        $afterRefund = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($afterRefund['is_reconciled']);
        $this->assertSame(0.00, $afterRefund['wallet_balance']);
        $this->assertSame(0.00, $afterRefund['ledger_balance']);
        $this->assertSame(0.00, $afterRefund['discrepancy']);
        $this->assertSame(0.00, $afterRefund['escrow_wallet_balance']);
        $this->assertSame(0.00, $afterRefund['escrow_ledger_balance']);
        $this->assertSame(0.00, $afterRefund['escrow_discrepancy']);
        $this->assertSame($seller->id, $afterRefund['seller_id']);
    }

    /**
     * Test 5: Subscription payment via wallet balance (METHOD_WALLET_BALANCE) -> wallet is deducted and ledger debit matches, 0.00 discrepancy.
     */
    public function test_subscription_payment_via_wallet_balance_reconciles_cleanly(): void
    {
        $seller = $this->createSeller();
        $customer = $this->createCustomer();

        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Verified Factory',
            'store_slug' => 'verified-factory',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $plan = SellerPlan::create([
            'name' => 'Premium Merchant',
            'slug' => 'premium-merchant',
            'subscription_price' => 400.00,
            'billing_cycle_days' => 30,
            'max_active_products' => 200,
            'retail_commission_rate' => 5.00,
            'wholesale_commission_rate' => 2.00,
            'is_active' => true,
        ]);

        $subscription = SellerSubscription::create([
            'seller_id' => $seller->id,
            'plan_id' => $plan->id,
            'status' => SellerSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        // Seed wallet and ledger with initial released earnings (1000.00)
        $codMethod = PaymentMethod::firstOrCreate(['slug' => 'cod'], ['name' => 'Cash On Delivery']);
        $unpaid = PaymentStatus::firstOrCreate(['slug' => 'unpaid'], ['name' => 'Unpaid']);
        PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        OrderStatus::firstOrCreate(['slug' => 'pending'], ['name' => 'Pending']);
        OrderStatus::firstOrCreate(['slug' => 'completed'], ['name' => 'Completed']);
        $outForDelivery = FulfillmentStatus::firstOrCreate(['slug' => 'out_for_delivery'], ['name' => 'Out For Delivery']);
        FulfillmentStatus::firstOrCreate(['slug' => 'delivered'], ['name' => 'Delivered']);

        $order = Order::create([
            'order_number' => 'ORD-SUB-REC-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => $unpaid->id,
            'payment_method_id' => $codMethod->id,
            'shipping_address' => ['address' => 'Sylhet'],
            'total_amount' => 1100.00,
            'shipping_amount' => 100.00,
            'discount_amount' => 0.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-SUB-REC-001-A',
            'fulfillment_status_id' => $outForDelivery->id,
            'subtotal' => 1000.00,
            'commission_amount' => 0.00,
            'shipping_amount' => 100.00,
            'is_self_delivered' => false,
        ]);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');
        app(EscrowLedgerService::class)->releaseFromEscrow($subOrder);

        // Initial wallet balance and ledger balance are 1000.00
        $initialCheck = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($initialCheck['is_reconciled']);
        $this->assertSame(1000.00, $initialCheck['wallet_balance']);
        $this->assertSame(1000.00, $initialCheck['ledger_balance']);

        // Deduct 400.00 from wallet for subscription invoice
        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-SUB-TEST-001',
            'seller_id' => $seller->id,
            'subscription_id' => $subscription->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => 400.00,
            'total_amount' => 400.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $wallet = SellerWallet::where('seller_id', $seller->id)->firstOrFail();
        $wallet->decrement('available_balance', 400.00);
        $wallet->refresh();

        // Create payment which triggers MonetizationJournalHandler to debit seller_wallet_payable
        SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $seller->id,
            'amount' => 400.00,
            'payment_method' => SellerPayment::METHOD_WALLET_BALANCE,
            'transaction_reference' => 'WALLET-SUB-TX-001',
            'status' => SellerPayment::STATUS_SUCCESSFUL,
            'verified_at' => now(),
        ]);

        $afterPayment = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($afterPayment['is_reconciled']);
        $this->assertSame(600.00, $afterPayment['wallet_balance']);
        $this->assertSame(600.00, $afterPayment['ledger_balance']);
        $this->assertSame(0.00, $afterPayment['discrepancy']);
        $this->assertSame(0.00, $afterPayment['escrow_wallet_balance']);
        $this->assertSame(0.00, $afterPayment['escrow_ledger_balance']);
        $this->assertSame(0.00, $afterPayment['escrow_discrepancy']);
        $this->assertSame($seller->id, $afterPayment['seller_id']);
    }

    /**
     * Test 6: Injected tamper/discrepancy test -> manually altering wallet balance is detected with is_reconciled = false and exact discrepancy amount.
     */
    public function test_injected_wallet_tampering_is_detected_with_exact_discrepancy(): void
    {
        $seller = $this->createSeller();
        $wallet = SellerWallet::create([
            'seller_id' => $seller->id,
            'available_balance' => 0.00,
            'escrow_held_balance' => 0.00,
        ]);

        $cleanCheck = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertTrue($cleanCheck['is_reconciled']);
        $this->assertSame(0.00, $cleanCheck['discrepancy']);

        // Injected tamper: maliciously alter available balance by +250.00 without ledger journal entry
        $wallet->update(['available_balance' => 250.00]);

        $tamperedWallet = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertFalse($tamperedWallet['is_reconciled']);
        $this->assertSame(250.00, $tamperedWallet['wallet_balance']);
        $this->assertSame(0.00, $tamperedWallet['ledger_balance']);
        $this->assertSame(250.00, $tamperedWallet['discrepancy']);
        $this->assertSame(0.00, $tamperedWallet['escrow_discrepancy']);

        // Reset and tamper escrow balance by +150.00
        $wallet->update([
            'available_balance' => 0.00,
            'escrow_held_balance' => 150.00,
        ]);

        $tamperedEscrow = $this->reconciliationService->verifySellerWallet($seller);
        $this->assertFalse($tamperedEscrow['is_reconciled']);
        $this->assertSame(0.00, $tamperedEscrow['discrepancy']);
        $this->assertSame(150.00, $tamperedEscrow['escrow_wallet_balance']);
        $this->assertSame(0.00, $tamperedEscrow['escrow_ledger_balance']);
        $this->assertSame(150.00, $tamperedEscrow['escrow_discrepancy']);
    }

    /**
     * Test 7: verifyAllSellers() across multiple sellers.
     */
    public function test_verify_all_sellers_aggregates_multiple_sellers(): void
    {
        $seller1 = $this->createSeller('Seller One');
        $seller2 = $this->createSeller('Seller Two');
        $seller3 = $this->createSeller('Seller Three');

        // Seller 1: Reconciled 0.00
        SellerWallet::create([
            'seller_id' => $seller1->id,
            'available_balance' => 0.00,
            'escrow_held_balance' => 0.00,
        ]);

        // Seller 2: Reconciled with held escrow
        $customer = $this->createCustomer();
        $bkashMethod = PaymentMethod::firstOrCreate(['slug' => 'bkash'], ['name' => 'bKash']);
        $paid = PaymentStatus::firstOrCreate(['slug' => 'paid'], ['name' => 'Paid']);
        $processing = FulfillmentStatus::firstOrCreate(['slug' => 'processing'], ['name' => 'Processing']);

        $order = Order::create([
            'order_number' => 'ORD-ALL-002',
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
            'seller_id' => $seller2->id,
            'sub_order_number' => 'ORD-ALL-002-A',
            'fulfillment_status_id' => $processing->id,
            'subtotal' => 450.00,
            'commission_amount' => 45.00,
            'shipping_amount' => 50.00,
            'is_self_delivered' => false,
        ]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        // Seller 3: Tampered / unreconciled wallet balance
        SellerWallet::create([
            'seller_id' => $seller3->id,
            'available_balance' => 500.00, // No GL entry
            'escrow_held_balance' => 0.00,
        ]);

        $summary = $this->reconciliationService->verifyAllSellers();

        $this->assertSame(3, $summary['total_sellers']);
        $this->assertSame(2, $summary['reconciled_count']);
        $this->assertSame(1, $summary['unreconciled_count']);
        $this->assertCount(3, $summary['details']);

        $seller3Detail = collect($summary['details'])->firstWhere('seller_id', $seller3->id);
        $this->assertNotNull($seller3Detail);
        $this->assertFalse($seller3Detail['is_reconciled']);
        $this->assertSame(500.00, $seller3Detail['discrepancy']);

        $seller1Detail = collect($summary['details'])->firstWhere('seller_id', $seller1->id);
        $this->assertNotNull($seller1Detail);
        $this->assertTrue($seller1Detail['is_reconciled']);

        $seller2Detail = collect($summary['details'])->firstWhere('seller_id', $seller2->id);
        $this->assertNotNull($seller2Detail);
        $this->assertTrue($seller2Detail['is_reconciled']);
        $this->assertSame(405.00, $seller2Detail['escrow_wallet_balance']);
        $this->assertSame(405.00, $seller2Detail['escrow_ledger_balance']);
    }

    /**
     * Test graceful handling when a seller has no wallet record created yet.
     */
    public function test_verify_seller_wallet_handles_missing_wallet_gracefully(): void
    {
        $seller = $this->createSeller('Uninitialized Seller');

        $result = $this->reconciliationService->verifySellerWallet($seller);

        $this->assertTrue($result['is_reconciled']);
        $this->assertSame(0.00, $result['wallet_balance']);
        $this->assertSame(0.00, $result['ledger_balance']);
        $this->assertSame(0.00, $result['discrepancy']);
        $this->assertSame(0.00, $result['escrow_wallet_balance']);
        $this->assertSame(0.00, $result['escrow_ledger_balance']);
        $this->assertSame(0.00, $result['escrow_discrepancy']);
        $this->assertSame($seller->id, $result['seller_id']);
    }
}
