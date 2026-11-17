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
     * WA-SEL-01: current behaviour; the fix inverts this assertion.
     * The seller's parcel page is a disabled generated form whose "Seller" select is filled from every user, so the
     * page source lists the names of other sellers, admins and every customer on the platform.
     */
    public function test_wa_sel_01_seller_parcel_page_lists_every_users_name(): void
    {
        $parcel = SubOrder::orderBy('id')->firstOrFail();
        $rival = $this->otherSeller();
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($parcel->seller);

        $this->get(route('filament.seller.resources.sub-orders.view', $parcel))
            ->assertOk()
            ->assertSee($rival->name, false)
            ->assertSee($customer->name, false);
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
     * WA-SEC-01: current behaviour; the fix inverts this assertion.
     * Coupon codes can be guessed without limit: 30 wrong codes in a row are all answered, none throttled.
     */
    public function test_wa_sec_01_coupon_code_guessing_is_not_throttled(): void
    {
        $this->actingAs(User::where('email', 'customer@retail.com')->firstOrFail());
        $cart = Livewire::test(CartComponent::class);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $cart->set('couponCode', 'GUESS'.$attempt)->call('applyCoupon');
            $this->assertStringContainsString('Invalid', (string) $cart->get('couponErrorMessage'), "attempt {$attempt} answered normally");
        }
    }
}
