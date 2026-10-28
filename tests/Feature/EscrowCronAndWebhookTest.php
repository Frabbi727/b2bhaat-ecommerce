<?php

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Escrow\EscrowLedgerService;
use Carbon\Carbon;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowCronAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_courier_webhook_updates_status_and_logs_milestone(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-WH-001',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'mock_gateway')->value('id'),
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 500.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-WH-001-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-WH-12345',
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
        ]);

        $response = $this->postJson('/api/v1/webhooks/courier', [
            'tracking_number' => 'ST-WH-12345',
            'status' => 'delivered',
            'title' => 'Delivered to Recipient',
            'description' => 'Parcel handed over to recipient successfully.',
            'hub_location' => 'Dhanmondi Hub',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('current_status', 'delivered');

        $subOrder->refresh();
        $this->assertEquals('delivered', $subOrder->fulfillmentStatus->slug);
        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Delivered to Recipient',
            'hub_location' => 'Dhanmondi Hub',
        ]);
    }

    public function test_escrow_release_command_releases_funds_after_48_hours(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $wallet = SellerWallet::where('seller_id', $seller->id)->firstOrFail();
        $initialHeld = (float) $wallet->escrow_held_balance;
        $initialAvailable = (float) $wallet->available_balance;

        $order = Order::create([
            'order_number' => 'ORD-CRON-002',
            'customer_id' => User::where('email', 'customer@retail.com')->value('id'),
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'mock_gateway')->value('id'),
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 1000.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-CRON-002-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id'),
            'subtotal' => 1000.00,
            'commission_amount' => 100.00, // net: 900.00
        ]);

        // Put in escrow
        $escrowService = app(EscrowLedgerService::class);
        $escrowService->holdInEscrow($subOrder);

        $wallet->refresh();
        $this->assertEquals($initialHeld + 900.00, (float) $wallet->escrow_held_balance);

        // 1. If delivered only 2 hours ago, command must NOT release yet
        $this->artisan('escrow:release-delivered', ['--hours' => 48])
            ->expectsOutputToContain('0 package(s) processed')
            ->assertSuccessful();

        $wallet->refresh();
        $this->assertEquals($initialHeld + 900.00, (float) $wallet->escrow_held_balance);

        // 2. Simulate 49 hours passed since delivery
        Carbon::setTestNow(now()->addHours(49));

        $this->artisan('escrow:release-delivered', ['--hours' => 48])
            ->expectsOutputToContain('ORD-CRON-002-A')
            ->expectsOutputToContain('1 package(s) processed')
            ->assertSuccessful();

        $wallet->refresh();
        $this->assertEquals($initialHeld, (float) $wallet->escrow_held_balance);
        $this->assertEquals($initialAvailable + 900.00, (float) $wallet->available_balance);

        Carbon::setTestNow(); // reset
    }
}
