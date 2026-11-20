<?php

namespace Tests\Feature;

use App\Livewire\Storefront\CustomerWishlist;
use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Cart\CartSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerWishlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_is_redirected_to_login_when_accessing_wishlist_page(): void
    {
        $response = $this->get(route('customer.wishlist'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_customer_can_view_empty_wishlist(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        $this->actingAs($customer);

        $response = $this->get(route('customer.wishlist'));
        $response->assertOk();
        $response->assertSee('My Wishlist');
        $response->assertSee('Your wishlist is empty');
    }

    public function test_customer_can_toggle_product_into_and_out_of_wishlist_from_product_detail(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        $product = Product::first();

        $this->actingAs($customer);

        // Toggle ADD to wishlist
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->call('toggleWishlist')
            ->assertSet('isInWishlist', true)
            ->assertSee('Product added to your Wishlist!');

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        // Toggle REMOVE from wishlist
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->call('toggleWishlist')
            ->assertSet('isInWishlist', false)
            ->assertSee('Product removed from your Wishlist.');

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_guest_toggle_wishlist_redirects_to_login(): void
    {
        $product = Product::first();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->call('toggleWishlist')
            ->assertRedirect(route('login'));

        $this->assertEquals(0, Wishlist::count());
    }

    public function test_wishlist_page_displays_saved_products_and_can_remove_them(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        $product = Product::first();

        $wishlist = Wishlist::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($customer);

        Livewire::test(CustomerWishlist::class)
            ->assertSee($product->title)
            ->call('removeFromWishlist', $wishlist->id)
            ->assertSee('Item removed from your wishlist.');

        $this->assertDatabaseMissing('wishlists', [
            'id' => $wishlist->id,
        ]);
    }

    public function test_customer_can_quick_add_wishlist_item_to_cart(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        // Pick a product without variations for direct quick-add
        $product = Product::where('has_variations', false)->first() ?? Product::first();

        Wishlist::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($customer);

        Livewire::test(CustomerWishlist::class)
            ->call('quickAddToCart', $product->id)
            ->assertDispatched('cartUpdated')
            ->assertSee('Product added to your cart successfully!');

        $cart = app(CartSessionService::class);
        $this->assertGreaterThan(0, $cart->getItemCount());
    }

    public function test_toggling_an_unapproved_product_from_the_catalog_saves_nothing(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        $product = Product::first();
        $product->forceFill(['approval_status' => 'pending_review'])->save();

        $this->actingAs($customer);

        Livewire::test(ProductCatalog::class)
            ->call('toggleWishlist', $product->id)
            ->assertSet('feedbackMessage', '')
            ->assertSet('errorMessage', 'This product is not available right now.');

        $this->assertDatabaseMissing('wishlists', ['user_id' => $customer->id, 'product_id' => $product->id]);
    }

    public function test_a_wishlist_row_for_an_unapproved_product_shows_no_details(): void
    {
        $customer = User::where('email', 'customer@retail.com')->first();
        $product = Product::first();
        $product->forceFill(['title' => 'Secret Prototype Lamp', 'regular_price' => null, 'approval_status' => 'pending_review'])->save();
        $product->prices()->update(['unit_price' => 4321.99]);

        Wishlist::create(['user_id' => $customer->id, 'product_id' => $product->id]);
        $this->actingAs($customer);

        Livewire::test(CustomerWishlist::class)
            ->assertSee('No longer available')
            ->assertDontSee('Secret Prototype Lamp')
            ->assertDontSee('4,321.99')
            ->assertDontSee($product->slug);
    }
}
