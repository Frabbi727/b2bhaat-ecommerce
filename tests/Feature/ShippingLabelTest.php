<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_view_shipping_label_for_own_sub_order(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $subOrder = SubOrder::where('seller_id', $seller->id)->firstOrFail();

        $this->actingAs($seller);

        $response = $this->get(route('seller.sub_order.label', $subOrder->sub_order_number));

        $response->assertStatus(200);
        $response->assertSee($subOrder->sub_order_number);
        $response->assertSee($subOrder->order->order_number);
        $response->assertSee('NailsFactory Ltd.');
        $response->assertSee('SHIP TO');
        $response->assertSee('B2B HAAT ESCROW LOGISTICS');
    }

    public function test_other_seller_cannot_view_foreign_sellers_shipping_label(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $otherSeller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Foreign Vendor',
            'email' => 'foreign@vendor.com',
            'phone' => '+8801711223388',
            'password' => 'password',
            'is_active' => true,
        ]);

        $subOrder = SubOrder::firstOrFail();

        $this->actingAs($otherSeller);

        $response = $this->get(route('seller.sub_order.label', $subOrder->sub_order_number));
        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_redirected_from_shipping_label(): void
    {
        $subOrder = SubOrder::firstOrFail();

        $response = $this->get(route('seller.sub_order.label', $subOrder->sub_order_number));
        $response->assertRedirect('/seller/login');
    }

    public function test_super_admin_can_view_any_shipping_label(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $subOrder = SubOrder::firstOrFail();

        $this->actingAs($admin);

        $response = $this->get(route('seller.sub_order.label', $subOrder->sub_order_number));
        $response->assertStatus(200);
        $response->assertSee($subOrder->sub_order_number);
    }
}
