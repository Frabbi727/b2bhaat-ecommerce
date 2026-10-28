<?php

namespace Tests\Feature;

use App\Livewire\Storefront\SellerStore;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_public_can_view_approved_seller_storefront(): void
    {
        $response = $this->get(route('store.show', 'nailsfactory-ltd'));

        $response->assertStatus(200);
        $response->assertSee('NailsFactory Ltd.');
        $response->assertSee('TRAD/DNCC/2026/09912');
        $response->assertSee('Govt. Trade Verified Supplier');
        $response->assertSee('Luxury Press-on Fake Nails Set');
    }

    public function test_unapproved_seller_storefront_returns_404(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $user = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Pending Vendor',
            'email' => 'pending@vendor.com',
            'phone' => '+8801799887766',
            'password' => 'password',
            'is_active' => true,
        ]);

        SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => 'Pending Factory Ltd',
            'store_slug' => 'pending-factory-ltd',
            'is_approved' => false,
        ]);

        $response = $this->get(route('store.show', 'pending-factory-ltd'));
        $response->assertStatus(404);
    }

    public function test_seller_storefront_search_filters_products_within_store(): void
    {
        Livewire::test(SellerStore::class, ['slug' => 'nailsfactory-ltd'])
            ->assertSee('Luxury Press-on Fake Nails Set')
            ->set('search', 'NonExistentProductXYZ')
            ->assertDontSee('Luxury Press-on Fake Nails Set')
            ->assertSee('No products found matching your search criteria');
    }

    public function test_wholesale_buyer_sees_unmasked_tier_prices_on_seller_store(): void
    {
        $wholesaleUser = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->actingAs($wholesaleUser);

        $response = $this->get(route('store.show', 'nailsfactory-ltd'));
        $response->assertStatus(200);
        $response->assertSee('Wholesale Price');
        $response->assertSee('110.00');
        $response->assertSee('MOQ: 50');
    }
}
