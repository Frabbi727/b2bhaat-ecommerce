<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\CourierBookingService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionReadinessAndSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_demo_login_forbidden_in_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/demo-login/admin');
        $response->assertStatus(403);
    }

    public function test_production_scheduled_commands_are_registered_in_console(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $commands = $events->map(fn ($event) => $event->command)->filter()->values()->all();

        $commandString = implode(' ', $commands);

        $this->assertStringContainsString('escrow:release-delivered', $commandString);
        $this->assertStringContainsString('inventory:release-expired-reservations', $commandString);
        $this->assertStringContainsString('promotions:sync-active-campaigns', $commandString);
    }

    public function test_invoice_idor_prevention_across_unrelated_accounts(): void
    {
        $customer1 = User::where('email', 'customer@retail.com')->firstOrFail();
        $customer2 = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-SEC-001',
            'customer_id' => $customer1->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->firstOrFail()->id,
            'total_amount' => 5000.00,
            'shipping_address' => [
                'recipient_name' => 'Customer One',
                'recipient_phone' => '01700000001',
                'street_address' => 'Private Address',
                'city' => 'Dhaka',
                'postal_code' => '1205',
            ],
        ]);

        // Customer 1 can view their invoice
        $this->actingAs($customer1)
            ->get(route('order.invoice', $order->order_number))
            ->assertStatus(200);

        // Customer 2 receives 403 Forbidden
        $this->actingAs($customer2)
            ->get(route('order.invoice', $order->order_number))
            ->assertStatus(403);
    }

    public function test_end_to_end_full_purchase_lifecycle(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = Product::with('variations')->where('seller_id', $seller->id)->firstOrFail();
        $variation = $product->variations->firstOrFail();

        $cart = app(CartSessionService::class);
        $cart->clear();

        // 1. Customer adds item to cart
        $cart->addItem(
            productId: $product->id,
            variationId: $variation->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        $this->assertEquals(2, $cart->getItemCount());

        // 2. Checkout & Order Placement with Cash on Delivery
        $cod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        $this->actingAs($customer);

        $subtotal = $cart->getSubtotal();
        $shippingFee = 70.00; // Inside Dhaka

        $orderPayload = [
            'payment_method_id' => $cod->id,
            'shipping_amount' => $shippingFee,
            'discount_amount' => 0.00,
            'shipping_address' => [
                'recipient_name' => $customer->name,
                'recipient_phone' => '01711223344',
                'street_address' => 'House 14, Road 7, Banani',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'delivery_zone' => 'inside_dhaka',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
                    'quantity' => 2,
                    'unit_price' => (float) $variation->product->effective_selling_price,
                ],
            ],
        ];

        $splitter = app(OrderSplitterService::class);
        $order = $splitter->createAndSplitOrder($customer, $orderPayload);

        $this->assertNotNull($order);
        $this->assertCount(1, $order->subOrders);

        $subOrder = $order->subOrders->first();
        $this->assertEquals($seller->id, $subOrder->seller_id);
        $this->assertEquals('unfulfilled', $subOrder->fulfillmentStatus?->slug);

        // 3. Seller schedules courier dispatch
        $booking = app(CourierBookingService::class);
        $booking->schedulePickup($subOrder, [
            'courier_name' => 'Steadfast Courier',
            'pickup_slot' => 'Today Express',
            'pickup_address' => 'Factory Gate 2, Dhaka',
        ]);

        $subOrder->refresh();
        $this->assertEquals('processing', $subOrder->fulfillmentStatus?->slug);
        $this->assertNotNull($subOrder->tracking_number);

        // 4. Courier marks consignment as Delivered via CourierStatusService
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );
        $initialBalance = (float) $wallet->available_balance;

        $courierStatus = app(CourierStatusService::class);
        $courierStatus->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'delivered',
            hubLocation: 'Customer Doorstep',
            title: 'Delivered & Cash Collected'
        );

        // 5. Verification of Final Production State
        $this->assertEquals('delivered', $subOrder->fresh()->fulfillmentStatus?->slug);
        $this->assertEquals('completed', $order->fresh()->orderStatus?->slug);
        $this->assertEquals('paid', $order->fresh()->paymentStatus?->slug);

        $netEarnings = $subOrder->net_earnings;
        $this->assertEquals($initialBalance + $netEarnings, (float) $wallet->fresh()->available_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => $netEarnings,
        ]);
    }
}
