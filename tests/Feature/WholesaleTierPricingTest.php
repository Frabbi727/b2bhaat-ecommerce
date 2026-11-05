<?php

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WholesaleTierPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_wholesale_buyer_sees_volume_tier_brackets_and_savings(): void
    {
        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($wholesaleBuyer);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Factory Volume Tier Brackets')
            ->assertSee('50 – 99')
            ->assertSee('100 – 249')
            ->assertSee('250+')
            ->assertSee('৳110.00')
            ->assertSee('৳95.00')
            ->assertSee('৳85.00');
    }

    public function test_bulk_matrix_applies_tier_1_rate_for_base_moq(): void
    {
        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variations = $product->variations()->get();

        $this->actingAs($wholesaleBuyer);

        $cart = app(CartSessionService::class);
        $cart->clear();

        // 50 total units -> Tier 1 (৳110.00)
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variations[0]->id}", 30)
            ->set("matrixQuantities.{$variations[1]->id}", 20)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');

        $cartItems = $cart->getItems();
        $this->assertNotEmpty($cartItems);

        foreach ($cartItems as $item) {
            $this->assertEquals(110.00, $item['unit_price']);
        }
    }

    public function test_bulk_matrix_applies_tier_2_rate_for_100_units(): void
    {
        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variations = $product->variations()->get();

        $this->actingAs($wholesaleBuyer);

        $cart = app(CartSessionService::class);
        $cart->clear();

        // 100 total units -> Tier 2 (৳95.00)
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variations[0]->id}", 60)
            ->set("matrixQuantities.{$variations[1]->id}", 40)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');

        $cartItems = $cart->getItems();
        $this->assertNotEmpty($cartItems);

        foreach ($cartItems as $item) {
            $this->assertEquals(95.00, $item['unit_price']);
        }
    }

    public function test_bulk_matrix_applies_tier_3_rate_for_250_units(): void
    {
        $wholesaleBuyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variations = $product->variations()->get();

        $this->actingAs($wholesaleBuyer);

        $cart = app(CartSessionService::class);
        $cart->clear();

        // 250 total units -> Tier 3 (৳85.00)
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variations[0]->id}", 150)
            ->set("matrixQuantities.{$variations[1]->id}", 100)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');

        $cartItems = $cart->getItems();
        $this->assertNotEmpty($cartItems);

        foreach ($cartItems as $item) {
            $this->assertEquals(85.00, $item['unit_price']);
        }
    }

    public function test_retail_user_sees_wholesale_teaser_without_tier_prices(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($retailUser);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Lower prices for bulk orders')
            ->assertDontSee('Wholesale Factory Volume Rates Available')
            ->assertDontSee('৳110.00')
            ->assertDontSee('৳85.00');
    }
}
