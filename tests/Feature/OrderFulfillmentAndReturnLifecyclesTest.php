<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ReturnRequest;
use App\Models\SalesChannel;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Logistics\CourierBookingService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFulfillmentAndReturnLifecyclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_seller_can_schedule_courier_pickup_and_assign_tracking(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-FULFILL-001',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'total_amount' => 5000.00,
            'shipping_amount' => 60.00,
            'shipping_address' => [
                'recipient_name' => 'Fatima Begum',
                'recipient_phone' => '01711223344',
                'street_address' => 'House 12, Road 4, Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1209',
            ],
        ]);

        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-FULFILL-001',
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'subtotal' => 5000.00,
            'commission_amount' => 250.00,
            'shipping_amount' => 60.00,
        ]);

        $bookingService = app(CourierBookingService::class);
        $updated = $bookingService->schedulePickup($subOrder, [
            'courier_name' => 'Steadfast Courier',
            'pickup_slot' => 'Today Express (2:00 PM - 6:00 PM)',
            'pickup_address' => 'Tejgaon Industrial Area, Dhaka',
            'estimated_weight' => '2.5 kg',
            'instructions' => 'Handle glass displays with caution',
        ]);

        $this->assertEquals('Steadfast Courier', $updated->courier_name);
        $this->assertStringStartsWith('ST-', $updated->tracking_number);
        $this->assertEquals('processing', $updated->fulfillmentStatus?->slug);
        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Courier Pickup Scheduled (Steadfast Courier)',
        ]);
    }

    public function test_seller_shipping_label_and_challan_access_authorization(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $otherSeller = User::create([
            'name' => 'Other Merchant',
            'email' => 'other_merchant@example.com',
            'password' => bcrypt('password'),
        ]);
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-AUTH-002',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'total_amount' => 3000.00,
            'shipping_amount' => 60.00,
            'shipping_address' => [
                'recipient_name' => 'Kamal Hossain',
                'recipient_phone' => '01811223344',
                'street_address' => 'Plot 5, Mirpur DOHS',
                'city' => 'Dhaka',
                'postal_code' => '1216',
            ],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-AUTH-002',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'processing')->firstOrFail()->id,
            'subtotal' => 3000.00,
            'commission_amount' => 150.00,
            'shipping_amount' => 60.00,
        ]);

        // 1. Assigned seller can view shipping label and challan
        $this->actingAs($seller)
            ->get(route('seller.sub_order.label', $subOrder->sub_order_number))
            ->assertStatus(200)
            ->assertSee('PKG-AUTH-002');

        $this->actingAs($seller)
            ->get(route('seller.sub_order.challan', $subOrder->sub_order_number))
            ->assertStatus(200)
            ->assertSee('DELIVERY CHALLAN')
            ->assertSee('PKG-AUTH-002');

        // 2. Unrelated seller gets 403 Forbidden
        $this->actingAs($otherSeller)
            ->get(route('seller.sub_order.label', $subOrder->sub_order_number))
            ->assertStatus(403);

        $this->actingAs($otherSeller)
            ->get(route('seller.sub_order.challan', $subOrder->sub_order_number))
            ->assertStatus(403);

        // 3. Super Admin can view both
        $this->actingAs($admin)
            ->get(route('seller.sub_order.label', $subOrder->sub_order_number))
            ->assertStatus(200);

        $this->actingAs($admin)
            ->get(route('seller.sub_order.challan', $subOrder->sub_order_number))
            ->assertStatus(200);
    }

    public function test_courier_status_delivered_settles_net_earnings_and_reconciles_parent_order(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-SETTLE-003',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'total_amount' => 10000.00,
            'shipping_amount' => 60.00,
            'shipping_address' => [
                'recipient_name' => 'Recipient Name',
                'recipient_phone' => '01700000000',
                'street_address' => 'Sample Address',
                'city' => 'Dhaka',
                'postal_code' => '1200',
            ],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-SETTLE-003',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->firstOrFail()->id,
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-261102-99881',
            'subtotal' => 10000.00,
            'commission_amount' => 500.00,
            'shipping_amount' => 60.00,
        ]);

        // Seller initial wallet balance
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $initialBalance = (float) $wallet->available_balance;

        // Transition status to delivered via CourierStatusService
        $courierStatusService = app(CourierStatusService::class);
        $courierStatusService->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'delivered',
            hubLocation: 'Customer Doorstep, Dhaka',
            title: 'Consignment Delivered & Cash Collected'
        );

        // 1. SubOrder is delivered
        $this->assertEquals('delivered', $subOrder->fresh()->fulfillmentStatus?->slug);

        // 2. Net earnings (10,000 - 500 = 9,500) credited to wallet
        $expectedNet = 9500.00;
        $this->assertEquals($initialBalance + $expectedNet, (float) $wallet->fresh()->available_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 9500.00,
        ]);

        // 3. Parent order reconciled: payment marked as paid, order marked as completed
        $this->assertEquals('paid', $order->fresh()->paymentStatus?->slug);
        $this->assertEquals('completed', $order->fresh()->orderStatus?->slug);
    }

    public function test_courier_webhook_endpoint_updates_package_status_and_logs(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-HOOK-004',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'total_amount' => 4500.00,
            'shipping_address' => [
                'recipient_name' => 'Recipient Name',
                'recipient_phone' => '01700000000',
                'street_address' => 'Sample Address',
                'city' => 'Dhaka',
                'postal_code' => '1200',
            ],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-HOOK-004',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'processing')->firstOrFail()->id,
            'courier_name' => 'RedX Delivery',
            'tracking_number' => 'RDX-261102-12345',
            'subtotal' => 4500.00,
            'commission_amount' => 200.00,
        ]);

        $response = $this->signedCourierPost([
            'tracking_number' => 'RDX-261102-12345',
            'status' => 'in_transit',
            'hub_location' => 'Tejgaon Central Sorting Facility',
            'title' => 'Parcel In Transit',
            'description' => 'Sorted and loaded onto feeder vehicle towards destination hub.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'current_status' => 'in_transit',
            ]);

        $this->assertEquals('in_transit', $subOrder->fresh()->fulfillmentStatus?->slug);
        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Parcel In Transit',
            'hub_location' => 'Tejgaon Central Sorting Facility',
        ]);
    }

    public function test_courier_status_returned_restores_variation_stock_and_reconciles_parent_order(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $product = Product::firstOrFail();
        $variation = ProductVariation::firstOrCreate([
            'product_id' => $product->id,
            'sku' => 'VAR-RTO-TEST',
        ], [
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $variation->update(['stock_quantity' => 10]);

        $order = Order::create([
            'order_number' => 'ORD-RTO-005',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cod')->firstOrFail()->id,
            'total_amount' => 2000.00,
            'shipping_address' => [
                'recipient_name' => 'Recipient Name',
                'recipient_phone' => '01700000000',
                'street_address' => 'Sample Address',
                'city' => 'Dhaka',
                'postal_code' => '1200',
            ],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-RTO-005',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->firstOrFail()->id,
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-RTO-005',
            'subtotal' => 2000.00,
            'commission_amount' => 100.00,
        ]);

        $channel = SalesChannel::firstOrFail();

        OrderItem::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder->id,
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'sales_channel_id' => $channel->id,
            'quantity' => 3,
            'unit_price' => 600.00,
            'total_price' => 1800.00,
        ]);

        // Mark as returned (customer refused / RTO)
        $courierStatusService = app(CourierStatusService::class);
        $courierStatusService->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'returned',
            hubLocation: 'Vendor Warehouse Return Bay',
            title: 'Returned to Origin (RTO)'
        );

        // 1. SubOrder status is returned
        $this->assertEquals('returned', $subOrder->fresh()->fulfillmentStatus?->slug);

        // 2. Stock is released back (+3 restored to 10 = 13)
        $this->assertEquals(13, $variation->fresh()->stock_quantity);

        // 3. Parent order marked as cancelled
        $this->assertEquals('cancelled', $order->fresh()->orderStatus?->slug);
    }

    public function test_customer_can_submit_return_request_and_seller_approval_refunds_settlement(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $product = Product::firstOrFail();
        $variation = ProductVariation::firstOrCreate([
            'product_id' => $product->id,
            'sku' => 'VAR-DISPUTE-TEST',
        ], [
            'stock_quantity' => 20,
            'is_active' => true,
        ]);
        $variation->update(['stock_quantity' => 20]);

        $order = Order::create([
            'order_number' => 'ORD-DISPUTE-006',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->firstOrFail()->id,
            'total_amount' => 8000.00,
            'shipping_address' => [
                'recipient_name' => 'Recipient Name',
                'recipient_phone' => '01700000000',
                'street_address' => 'Sample Address',
                'city' => 'Dhaka',
                'postal_code' => '1200',
            ],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-DISPUTE-006',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->firstOrFail()->id,
            'courier_name' => 'Pathao Courier',
            'tracking_number' => 'PTH-261102-55443',
            'subtotal' => 8000.00,
            'commission_amount' => 400.00,
        ]);

        $channel = SalesChannel::firstOrFail();

        OrderItem::create([
            'order_id' => $order->id,
            'sub_order_id' => $subOrder->id,
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'sales_channel_id' => $channel->id,
            'quantity' => 2,
            'unit_price' => 4000.00,
            'total_price' => 8000.00,
        ]);

        // Credit seller wallet first (as if delivered)
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $initialBalance = (float) $wallet->available_balance;
        $wallet->increment('available_balance', 7600.00); // 8,000 - 400 = 7,600

        // Customer submits return request
        $disputeService = app(OrderDisputeService::class);
        $returnRequest = $disputeService->submitReturnRequest($subOrder, $customer, [
            'reason' => 'Defective internal wiring',
            'requested_action' => 'refund',
            'description' => 'Device shuts down after 5 minutes of operation.',
        ]);

        $this->assertEquals(ReturnRequest::STATUS_PENDING, $returnRequest->status);

        // Seller resolves return request with APPROVAL
        $disputeService->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_APPROVED, 'Approved for refund. Item accepted.');

        // 1. Return request status is approved
        $this->assertEquals(ReturnRequest::STATUS_APPROVED, $returnRequest->fresh()->status);

        // 2. Sub-order fulfillment status updated to returned
        $this->assertEquals('returned', $subOrder->fresh()->fulfillmentStatus?->slug);

        // 3. Net earnings (7,600) reversed from seller wallet balance back to initial balance
        $this->assertEquals($initialBalance, (float) $wallet->fresh()->available_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_REFUND,
            'amount' => 7600.00,
        ]);

        // 4. Stock restored (+2 restored to 20 = 22)
        $this->assertEquals(22, $variation->fresh()->stock_quantity);

        // 5. Parent order updated to cancelled / refunded
        $this->assertEquals('cancelled', $order->fresh()->orderStatus?->slug);
        $this->assertEquals('refunded', $order->fresh()->paymentStatus?->slug);
    }

    public function test_sub_order_dynamic_tracking_url_generation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $subOrder1 = new SubOrder([
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-998877',
        ]);
        $this->assertEquals('https://steadfast.com.bd/t/ST-998877', $subOrder1->tracking_url);

        $subOrder2 = new SubOrder([
            'courier_name' => 'Pathao Courier Express',
            'tracking_number' => 'PTH-112233',
        ]);
        $this->assertEquals('https://merchant.pathao.com/tracking?consignment_id=PTH-112233', $subOrder2->tracking_url);

        $subOrder3 = new SubOrder([
            'courier_name' => 'RedX Delivery',
            'tracking_number' => 'RDX-445566',
        ]);
        $this->assertEquals('https://redx.com.bd/track?trackingId=RDX-445566', $subOrder3->tracking_url);

        $subOrder4 = new SubOrder([
            'courier_name' => 'Paperfly Delivery Hub',
            'tracking_number' => 'PFLY-778899',
        ]);
        $this->assertEquals('https://paperfly.com.bd/tracking?id=PFLY-778899', $subOrder4->tracking_url);

        $subOrder5 = new SubOrder([
            'courier_name' => 'Sundarban Courier Ltd',
            'tracking_number' => 'SBN-001122',
        ]);
        $this->assertEquals('https://sundarbancourierltd.com/track/SBN-001122', $subOrder5->tracking_url);

        $subOrderNone = new SubOrder([
            'courier_name' => null,
            'tracking_number' => null,
        ]);
        $this->assertNull($subOrderNone->tracking_url);
    }
}
