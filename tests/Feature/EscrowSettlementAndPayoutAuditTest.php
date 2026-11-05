<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowSettlementAndPayoutAuditTest extends TestCase
{
    use RefreshDatabase;

    protected EscrowLedgerService $escrowService;

    protected OrderDisputeService $disputeService;

    protected CourierStatusService $courierStatusService;

    protected StoreConfigurationService $configService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->escrowService = app(EscrowLedgerService::class);
        $this->disputeService = app(OrderDisputeService::class);
        $this->courierStatusService = app(CourierStatusService::class);
        $this->configService = app(StoreConfigurationService::class);
    }

    public function test_prepaid_order_places_escrow_hold_and_cancellation_refunds_escrow_cleanly(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $wallet->update(['available_balance' => 0.00, 'escrow_held_balance' => 0.00]);

        $orderStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
        $paymentStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'sslcommerz')->firstOrFail();
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-ESCROW-001',
            'customer_id' => $customer->id,
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka', 'address' => 'Banani, Block C'],
            'total_amount' => 5000.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-ESCROW-001-A',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 5000.00,
            'commission_amount' => 500.00, // net: 4500.00
            'shipping_amount' => 70.00,
        ]);

        // 1. Hold net earnings in escrow upon prepaid checkout
        $holdTx = $this->escrowService->holdInEscrow($subOrder);
        $this->assertEquals(WalletTransaction::TYPE_ESCROW_HOLD, $holdTx->transaction_type);
        $this->assertEquals(4500.00, (float) $holdTx->amount);

        $wallet->refresh();
        $this->assertEquals(4500.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals(0.00, (float) $wallet->available_balance);

        // Verify hold idempotency: repeat hold returns same record without double crediting
        $repeatHoldTx = $this->escrowService->holdInEscrow($subOrder);
        $this->assertEquals($holdTx->id, $repeatHoldTx->id);
        $wallet->refresh();
        $this->assertEquals(4500.00, (float) $wallet->escrow_held_balance);

        // 2. Buyer cancels the unfulfilled consignment
        $this->disputeService->cancelSubOrder($subOrder, 'Buyer changed mind before shipping');

        $subOrder->refresh();
        $order->refresh();
        $wallet->refresh();

        // 3. Verify sub-order status cancelled
        $this->assertEquals(FulfillmentStatus::where('slug', 'cancelled')->value('id'), $subOrder->fulfillment_status_id);

        // 4. Verify wallet escrow held is restored to zero and available balance remains unaffected
        $this->assertEquals(0.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals(0.00, (float) $wallet->available_balance);

        // 5. Verify refund transaction is recorded in ledger
        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_REFUND,
            'amount' => 4500.00,
        ]);

        // 6. Verify parent order is marked cancelled and payment refunded
        $this->assertEquals(OrderStatus::where('slug', 'cancelled')->value('id'), $order->order_status_id);
        $this->assertEquals(PaymentStatus::where('slug', 'refunded')->value('id'), $order->payment_status_id);

        // 7. Verify refund idempotency: repeat refund does nothing
        $repeatRefundTx = $this->escrowService->refundCancelledEscrow($subOrder);
        $this->assertNotNull($repeatRefundTx);
        $this->assertEquals(WalletTransaction::TYPE_ESCROW_REFUND, $repeatRefundTx->transaction_type);
        $this->assertEquals(1, WalletTransaction::where('sub_order_id', $subOrder->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_REFUND)->count());
    }

    public function test_delivered_consignment_is_released_once_after_holding_period(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $wallet->update(['available_balance' => 0.00, 'escrow_held_balance' => 0.00]);

        $orderStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
        $paymentStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'bkash')->firstOrFail();
        $shippedStatus = FulfillmentStatus::where('slug', 'shipped')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-DELIVER-001',
            'customer_id' => $customer->id,
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 3000.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-DELIVER-001-A',
            'fulfillment_status_id' => $shippedStatus->id,
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-99887766',
            'subtotal' => 3000.00,
            'commission_amount' => 300.00, // net: 2700.00
            'shipping_amount' => 70.00,
        ]);

        // Put in escrow first
        $this->escrowService->holdInEscrow($subOrder);
        $wallet->refresh();
        $this->assertEquals(2700.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals(0.00, (float) $wallet->available_balance);

        // Courier marks consignment delivered
        $this->courierStatusService->updateStatus($subOrder, 'delivered');

        // Delivery keeps the funds in escrow; only the 48 h release moves them (PRA-001)
        $wallet->refresh();
        $this->assertEquals(2700.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals(0.00, (float) $wallet->available_balance);

        // Duplicate delivery webhook arrives (must be idempotent - NO duplicate hold)
        $this->courierStatusService->updateStatus($subOrder, 'delivered');
        $wallet->refresh();
        $this->assertEquals(2700.00, (float) $wallet->escrow_held_balance);

        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->artisan('escrow:release-delivered')->assertSuccessful();

        $wallet->refresh();
        $this->assertEquals(0.00, (float) $wallet->escrow_held_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 2700.00,
        ]);
        $this->assertEquals(2700.00, (float) $wallet->available_balance); // Not 5400.00!
        $this->assertEquals(1, WalletTransaction::where('sub_order_id', $subOrder->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->count());
    }

    public function test_escrow_release_command_honors_dynamic_holding_hours(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $wallet->update(['available_balance' => 0.00, 'escrow_held_balance' => 0.00]);

        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->firstOrFail();
        $orderStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
        $paymentStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
        $paymentMethod = PaymentMethod::where('slug', 'nagad')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-CRON-001',
            'customer_id' => $customer->id,
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 2000.00,
        ]);

        // SubOrder mature (delivered 50 hours ago)
        $matureSubOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-CRON-001-A',
            'fulfillment_status_id' => $deliveredStatus->id,
            'subtotal' => 2000.00,
            'commission_amount' => 200.00, // net: 1800.00
            'shipping_amount' => 70.00,
            'updated_at' => now()->subHours(50),
        ]);

        OrderTrackingLog::create([
            'sub_order_id' => $matureSubOrder->id,
            'fulfillment_status_id' => $deliveredStatus->id,
            'title' => 'Delivered to Customer',
            'hub_location' => 'Customer Handover Point',
            'logged_at' => now()->subHours(50),
        ]);

        // Put in escrow manually without auto-releasing
        $wallet->increment('escrow_held_balance', 1800.00);
        WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $matureSubOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_HOLD,
            'amount' => 1800.00,
            'running_balance' => 0.00,
            'reference_note' => 'Prepaid escrow hold',
        ]);

        // Run artisan release command with dynamic default threshold (48h)
        $this->artisan('escrow:release-delivered')
            ->expectsOutputToContain('Escrow release cycle completed')
            ->assertExitCode(0);

        $wallet->refresh();
        $this->assertEquals(0.00, (float) $wallet->escrow_held_balance);
        $this->assertEquals(1800.00, (float) $wallet->available_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $matureSubOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 1800.00,
        ]);
    }

    public function test_seller_cannot_request_payout_below_minimum_or_exceeding_balance(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $wallet->update(['available_balance' => 1000.00, 'escrow_held_balance' => 0.00]);

        // Default min payout is 500.00
        $this->assertEquals(500.00, $this->configService->getMinPayoutAmount());

        // 1. Attempting payout below minimum (e.g. 300.00) must throw exception
        $rejectedLow = false;
        try {
            $this->escrowService->requestPayout($seller, 300.00, 'bKash personal withdrawal');
        } catch (Exception $e) {
            $rejectedLow = true;
            $this->assertStringContainsString('Payout withdrawal amount must be at least ৳500', $e->getMessage());
        }
        $this->assertTrue($rejectedLow, 'Expected payout request below minimum threshold to be rejected.');

        // 2. Attempting payout exceeding available balance (e.g. 1500.00) must throw exception
        $rejectedOver = false;
        try {
            $this->escrowService->requestPayout($seller, 1500.00, 'bKash personal withdrawal');
        } catch (Exception $e) {
            $rejectedOver = true;
            $this->assertStringContainsString('Insufficient available balance', $e->getMessage());
        }
        $this->assertTrue($rejectedOver, 'Expected payout request exceeding available balance to be rejected.');

        // 3. Valid payout request (e.g. 600.00) must succeed
        $payoutTx = $this->escrowService->requestPayout($seller, 600.00, 'bKash personal 01711223344');
        $this->assertEquals(WalletTransaction::TYPE_PAYOUT, $payoutTx->transaction_type);
        $this->assertEquals(600.00, (float) $payoutTx->amount);

        $wallet->refresh();
        $this->assertEquals(400.00, (float) $wallet->available_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'amount' => 600.00,
            'running_balance' => 400.00,
        ]);
    }

    public function test_multi_vendor_split_order_correctly_partitions_commissions_and_seller_earnings(): void
    {
        $sellerA = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $sellerB = User::where('email', 'seller@dhakafashion.com')->first();

        $walletA = SellerWallet::firstOrCreate(
            ['seller_id' => $sellerA->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $walletA->update(['available_balance' => 0.00, 'escrow_held_balance' => 0.00]);

        if (! $sellerB) {
            $sellerRole = Role::where('slug', 'seller')->firstOrFail();
            $sellerB = User::create([
                'name' => 'Dhaka Electronics Ltd',
                'email' => 'seller@dhakafashion.com',
                'password' => bcrypt('password'),
                'phone' => '01700998877',
                'role_id' => $sellerRole->id,
            ]);
            SellerProfile::create([
                'user_id' => $sellerB->id,
                'store_name' => 'Dhaka Electronics Ltd',
                'store_slug' => 'dhaka-electronics-ltd',
                'trade_license_number' => 'TRAD-998811',
                'commission_rate_percentage' => 12.00,
                'is_approved' => true,
            ]);
        }

        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        // Product from Seller A (10% commission default)
        $productA = Product::where('seller_id', $sellerA->id)->firstOrFail();
        $variationA = $productA->variations()->firstOrFail();

        // Product from Seller B (12% commission profile)
        $productB = Product::create([
            'seller_id' => $sellerB->id,
            'category_id' => $productA->category_id,
            'brand_id' => $productA->brand_id,
            'unit_id' => $productA->unit_id,
            'sales_channel_id' => $retailChannel->id,
            'title' => 'Multi-Vendor Smart Audio Speaker',
            'slug' => 'multi-vendor-smart-audio-speaker',
            'sku' => 'SKU-MVS-SPEAKER',
            'regular_price' => 2000.00,
            'is_active' => true,
        ]);
        $variationB = ProductVariation::create([
            'product_id' => $productB->id,
            'sku' => 'MVS-SPEAKER-01',
            'price' => 2000.00,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        $splitterService = app(OrderSplitterService::class);

        $order = $splitterService->createAndSplitOrder($customer, [
            'payment_method_id' => PaymentMethod::where('slug', 'sslcommerz')->value('id'),
            'shipping_address' => [
                'recipient_name' => 'Farhan Chowdhury',
                'phone' => '01819998877',
                'district' => 'Dhaka',
                'city' => 'Dhaka',
                'street' => 'Road 11, Banani',
            ],
            'items' => [
                [
                    'product_id' => $productA->id,
                    'variation_id' => $variationA->id,
                    'quantity' => 2,
                    'unit_price' => (float) $variationA->price,
                    'sales_channel_id' => $retailChannel->id,
                ],
                [
                    'product_id' => $productB->id,
                    'variation_id' => $variationB->id,
                    'quantity' => 1,
                    'unit_price' => 2000.00,
                    'sales_channel_id' => $retailChannel->id,
                ],
            ],
        ]);

        $subOrders = $order->subOrders;

        $this->assertCount(2, $subOrders);

        $subOrderA = $subOrders->firstWhere('seller_id', $sellerA->id);
        $subOrderB = $subOrders->firstWhere('seller_id', $sellerB->id);

        $this->assertNotNull($subOrderA);
        $this->assertNotNull($subOrderB);

        // Seller A verification
        $expectedSubtotalA = (float) $variationA->price * 2;
        $this->assertEquals($expectedSubtotalA, (float) $subOrderA->subtotal);
        $this->assertEquals(round($expectedSubtotalA * 0.10, 2), (float) $subOrderA->commission_amount);
        $this->assertEquals(round($expectedSubtotalA * 0.90, 2), (float) $subOrderA->net_earnings);

        // Seller B verification (12% commission)
        $this->assertEquals(2000.00, (float) $subOrderB->subtotal);
        $this->assertEquals(240.00, (float) $subOrderB->commission_amount); // 12% of 2000
        $this->assertEquals(1760.00, (float) $subOrderB->net_earnings);

        // Hold both suborders in escrow
        $txA = $this->escrowService->holdInEscrow($subOrderA);
        $txB = $this->escrowService->holdInEscrow($subOrderB);

        $walletA = SellerWallet::where('seller_id', $sellerA->id)->firstOrFail();
        $walletB = SellerWallet::where('seller_id', $sellerB->id)->firstOrFail();

        $this->assertEquals($subOrderA->net_earnings, (float) $walletA->escrow_held_balance);
        $this->assertEquals(1760.00, (float) $walletB->escrow_held_balance);
        $this->assertEquals(0.00, (float) $walletA->available_balance);
        $this->assertEquals(0.00, (float) $walletB->available_balance);
    }
}
