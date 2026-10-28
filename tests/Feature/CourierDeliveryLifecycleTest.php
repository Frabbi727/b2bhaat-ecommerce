<?php

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\OrderStatus;
use App\Models\PaymentStatus;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierDeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_courier_status_service_transitions_to_in_transit(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $service = app(CourierStatusService::class);

        $service->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'in_transit',
            hubLocation: 'Gazipur Sorting Center',
            title: 'Package Arrived at Hub',
            description: 'Scanned into Gazipur regional sort line.'
        );

        $this->assertDatabaseHas('sub_orders', [
            'id' => $subOrder->id,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'in_transit')->value('id'),
        ]);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'hub_location' => 'Gazipur Sorting Center',
            'title' => 'Package Arrived at Hub',
        ]);
    }

    public function test_marking_delivered_settles_seller_wallet_and_reconciles_cod(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $seller = $subOrder->seller;
        $parentOrder = $subOrder->order;

        // Ensure wallet exists
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 1000.00, 'escrow_held_balance' => 0.00]
        );
        $initialBalance = (float) $wallet->available_balance;
        $expectedNet = (float) $subOrder->net_earnings;

        $service = app(CourierStatusService::class);
        $service->updateStatus(
            subOrder: $subOrder,
            statusSlug: 'delivered',
            hubLocation: 'Dhanmondi Customer Doorstep'
        );

        // 1. SubOrder status is delivered
        $this->assertDatabaseHas('sub_orders', [
            'id' => $subOrder->id,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id'),
        ]);

        // 2. Seller wallet received net earnings
        $wallet->refresh();
        $this->assertEquals($initialBalance + $expectedNet, (float) $wallet->available_balance);

        // 3. Wallet transaction recorded
        $this->assertDatabaseHas('wallet_transactions', [
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => $expectedNet,
        ]);

        // 4. Parent order COD marked as paid and completed
        $parentOrder->refresh();
        $this->assertEquals(PaymentStatus::where('slug', 'paid')->value('id'), $parentOrder->payment_status_id);
        $this->assertEquals(OrderStatus::where('slug', 'completed')->value('id'), $parentOrder->order_status_id);
    }

    public function test_courier_webhook_endpoint_ingests_status_event(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $subOrder->update(['tracking_number' => 'STDFST-TEST-9988']);

        $response = $this->postJson('/api/v1/webhooks/courier', [
            'tracking_number' => 'STDFST-TEST-9988',
            'status' => 'out_for_delivery',
            'hub_location' => 'Mirpur Delivery Hub',
            'title' => 'Rider Assigned',
            'description' => 'Delivery associate is heading to recipient address.',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'current_status' => 'out_for_delivery',
        ]);

        $this->assertDatabaseHas('sub_orders', [
            'id' => $subOrder->id,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
        ]);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'hub_location' => 'Mirpur Delivery Hub',
            'title' => 'Rider Assigned',
        ]);
    }
}
