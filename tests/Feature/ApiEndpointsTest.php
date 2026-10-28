<?php

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_can_register_retail_user_via_api(): void
    {
        $response = $this->postJson('/api/v1/auth/register/retail', [
            'name' => 'Alice Rahman',
            'email' => 'alice@example.com',
            'phone' => '+8801711223344',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', 'retail_buyer')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_can_register_wholesale_user_via_api(): void
    {
        $response = $this->postJson('/api/v1/auth/register/wholesale', [
            'name' => 'Boutique Owner',
            'email' => 'boutique@example.com',
            'phone' => '+8801755667788',
            'password' => 'SecurePass123!',
            'trade_license_number' => 'TRAD-2026-X',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', 'wholesale_buyer')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_can_register_seller_via_api(): void
    {
        $response = $this->postJson('/api/v1/auth/register/seller', [
            'name' => 'Factory Owner',
            'email' => 'factory@example.com',
            'phone' => '+8801799887766',
            'password' => 'SecurePass123!',
            'store_name' => 'Garments Direct Ltd',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', 'seller')
            ->assertJsonPath('user.is_approved', false);
    }

    public function test_can_login_via_api(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@b2bhaat.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'super_admin')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_public_catalog_endpoint_masks_wholesale_pricing(): void
    {
        $response = $this->getJson('/api/v1/catalog/products/luxury-press-on-fake-nails-set');

        $response->assertStatus(200)
            ->assertJsonPath('data.pricing.retail_price', 250)
            ->assertJsonPath('data.wholesale_teaser.available', true)
            ->assertJsonMissing(['wholesale_price', 'buy_price']);
    }

    public function test_public_order_tracking_endpoint(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-TRACK-101',
            'customer_id' => $customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'shipping_address' => [
                'recipient_name' => 'Tanvir Hasan',
                'recipient_phone' => '+8801700000004',
                'street_address' => 'Dhanmondi, Dhaka',
                'city' => 'Dhaka',
                'postal_code' => '1205',
            ],
            'total_amount' => 500.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'ORD-TRACK-101-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'ST-992381',
            'subtotal' => 500.00,
            'commission_amount' => 50.00,
        ]);

        $response = $this->getJson('/api/v1/orders/track?number=ORD-TRACK-101&phone=+8801700000004');

        $response->assertStatus(200)
            ->assertJsonPath('data.order_number', 'ORD-TRACK-101')
            ->assertJsonPath('data.packages.0.courier_name', 'Steadfast Courier')
            ->assertJsonPath('data.packages.0.tracking_number', 'ST-992381');
    }
}
