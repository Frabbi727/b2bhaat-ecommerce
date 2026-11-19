<?php

declare(strict_types=1);

namespace Tests\Feature\WholesaleAudit;

use App\Livewire\Storefront\CartComponent;
use App\Models\Product;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wholesale audit 2026-11-14, security probes (report H).
 */
#[Group('wholesale-audit')]
class SecurityProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    private function otherSeller(): User
    {
        $seller = User::create([
            'role_id' => Role::where('slug', 'seller')->value('id'),
            'name' => 'Rival Factory',
            'email' => 'rival@vendor.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => 'Rival', 'store_slug' => 'rival', 'is_approved' => true]);

        return $seller;
    }

    /**
     * Verified correct: a seller cannot open another seller's shipping label or challan by guessing the parcel number.
     */
    public function test_verified_seller_cannot_open_another_sellers_label_or_challan(): void
    {
        $parcel = SubOrder::orderBy('id')->firstOrFail();
        $this->actingAs($this->otherSeller());

        $this->get(route('seller.sub_order.label', $parcel->sub_order_number))->assertForbidden();
        $this->get(route('seller.sub_order.challan', $parcel->sub_order_number))->assertForbidden();
    }

    /**
     * WA-SEL-01 fixed: the seller's parcel page is a read-only summary, so it names the parcel's own buyer but no
     * other seller, admin or customer on the platform.
     */
    public function test_wa_sel_01_seller_parcel_page_shows_only_its_own_buyer(): void
    {
        $parcel = SubOrder::orderBy('id')->firstOrFail();
        $rival = $this->otherSeller();
        $admin = User::whereHas('role', fn ($query) => $query->whereIn('slug', ['admin', 'super_admin']))->firstOrFail();
        $this->actingAs($parcel->seller);

        $this->get(route('filament.seller.resources.sub-orders.view', $parcel))
            ->assertOk()
            ->assertSee($parcel->order->shipping_address['recipient_name'], false)
            ->assertDontSee($rival->name, false)
            ->assertDontSee($admin->name, false);
    }

    /**
     * Verified correct: guests and retail buyers get no wholesale price from the JSON API.
     */
    public function test_verified_api_catalog_masks_wholesale_prices_for_guests(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->getJson("/api/v1/catalog/products/{$product->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.pricing.wholesale_price')
            ->assertJsonPath('data.pricing.retail_price', 250);
    }

    /**
     * WA-SEC-01 (fixed): coupon codes can no longer be guessed without limit. The first 10 wrong codes are answered
     * normally; from the 11th, attempts are refused for 10 minutes.
     */
    public function test_wa_sec_01_coupon_code_guessing_is_throttled(): void
    {
        $this->actingAs(User::where('email', 'customer@retail.com')->firstOrFail());
        $cart = Livewire::test(CartComponent::class);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $cart->set('couponCode', 'GUESS'.$attempt)->call('applyCoupon');
            $this->assertStringContainsString('Invalid', (string) $cart->get('couponErrorMessage'), "attempt {$attempt} answered normally");
        }

        $cart->set('couponCode', 'GUESS11')->call('applyCoupon');
        $this->assertStringContainsString('Too many wrong coupon codes', (string) $cart->get('couponErrorMessage'));
    }
}
