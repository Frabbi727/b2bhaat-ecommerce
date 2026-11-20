<?php

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Logistics\CourierBookingService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierBookingAndChallanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_schedule_automated_courier_pickup(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $subOrder = SubOrder::where('seller_id', $seller->id)->firstOrFail();
        // A pickup is booked before the parcel ships; a shipped parcel can't go back to processing (ORD-2).
        $subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);

        $service = app(CourierBookingService::class);
        $updated = $service->schedulePickup($subOrder, [
            'courier_name' => 'Steadfast Courier',
            'pickup_slot' => 'Today Express (2:00 PM - 6:00 PM)',
            'pickup_address' => 'NailsFactory Warehouse, Mirpur-12, Dhaka',
            'estimated_weight' => '2.5 kg (2 Cartons)',
            'instructions' => 'Fragile beauty products',
        ]);

        $this->assertSame('Steadfast Courier', $updated->courier_name);
        $this->assertStringStartsWith('ST-', $updated->tracking_number);
        $this->assertSame('processing', $updated->fulfillmentStatus?->slug);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'hub_location' => 'NailsFactory Warehouse, Mirpur-12, Dhaka',
        ]);
    }

    public function test_scheduling_pickup_fails_for_already_delivered_package(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $subOrder = SubOrder::where('seller_id', $seller->id)->firstOrFail();

        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => $deliveredStatus->id]);

        $service = app(CourierBookingService::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Cannot move a consignment from 'delivered' to 'processing'.");

        $service->schedulePickup($subOrder, [
            'courier_name' => 'Pathao Courier',
            'pickup_slot' => 'Tomorrow Morning',
            'pickup_address' => 'Warehouse',
        ]);
    }

    public function test_seller_can_view_delivery_challan_for_their_sub_order(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $subOrder = SubOrder::where('seller_id', $seller->id)->firstOrFail();

        $this->actingAs($seller);

        $response = $this->get(route('seller.sub_order.challan', $subOrder->sub_order_number));

        $response->assertStatus(200);
        $response->assertSee('DELIVERY CHALLAN');
        $response->assertSee('CH-'.$subOrder->sub_order_number);
        $response->assertSee('Gate Out Clearance');
        $response->assertSee('Courier Rider / Agent');
    }

    public function test_seller_cannot_view_another_sellers_delivery_challan(): void
    {
        $otherSeller = User::create([
            'role_id' => Role::where('slug', 'seller')->value('id'),
            'name' => 'Foreign Merchant',
            'email' => 'foreign@merchant.com',
            'password' => bcrypt('password'),
        ]);

        $subOrder = SubOrder::firstOrFail();

        $this->actingAs($otherSeller);

        $response = $this->get(route('seller.sub_order.challan', $subOrder->sub_order_number));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_from_delivery_challan(): void
    {
        $subOrder = SubOrder::firstOrFail();

        $response = $this->get(route('seller.sub_order.challan', $subOrder->sub_order_number));

        $response->assertRedirect();
    }

    public function test_admin_can_view_any_delivery_challan(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $subOrder = SubOrder::firstOrFail();

        $this->actingAs($admin);

        $response = $this->get(route('seller.sub_order.challan', $subOrder->sub_order_number));

        $response->assertStatus(200);
        $response->assertSee('DELIVERY CHALLAN');
        $response->assertSee('CH-'.$subOrder->sub_order_number);
    }
}
