<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX-7: the wholesale minimum order is stated where the buyer chooses quantities (product page) and where they
 * review them (cart), not first at checkout. Every screen uses the cart's rule: the lowest wholesale tier.
 * Demo tiers: 50–99 @ ৳110, 100–249 @ ৳95, 250+ @ ৳85.
 */
class WholesaleMinimumShownTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        app(CartSessionService::class)->clear();
    }

    private function actAsWholesaleBuyer(): void
    {
        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail());
    }

    public function test_the_product_page_states_the_minimum_next_to_the_order_matrix(): void
    {
        $this->actAsWholesaleBuyer();

        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->assertSee('Minimum order: 50')
            ->assertSee('all options together');
    }

    public function test_buyers_without_wholesale_prices_see_where_bulk_orders_start(): void
    {
        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->assertSee('Bulk orders start at 50');
    }

    public function test_the_product_page_applies_the_lowest_tier_as_the_minimum(): void
    {
        $this->actAsWholesaleBuyer();

        // The first stored tier row is no longer the lowest one
        $tiers = ProductPrice::where('product_id', $this->product->id)
            ->whereHas('salesChannel', fn ($query) => $query->where('slug', SalesChannel::WHOLESALE))
            ->orderBy('id')->get();
        [$lowest, $highest] = [$tiers->first(), $tiers->last()];
        $lowestRange = $lowest->only(['min_quantity', 'max_quantity', 'unit_price']);
        $lowest->update($highest->only(['min_quantity', 'max_quantity', 'unit_price']));
        $highest->update($lowestRange);

        $variation = $this->product->variations()->orderBy('id')->firstOrFail();
        $variation->update(['stock_quantity' => 500]);

        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->assertSee('Minimum order: 50')
            ->set("matrixQuantities.{$variation->id}", 60)
            ->call('addBulkMatrixToCart')
            ->assertSet('errorMessage', '');

        $this->assertSame(60, app(CartSessionService::class)->getItemCount());
    }

    public function test_the_cart_warns_about_a_lot_below_the_minimum(): void
    {
        $this->actAsWholesaleBuyer();
        [$first, $second] = $this->product->variations()->orderBy('id')->take(2)->get()->all();

        $cart = app(CartSessionService::class);
        $cart->addWholesaleLot($this->product->id, [$first->id => 30, $second->id => 20]);
        $cart->removeItem("{$this->product->id}_{$second->id}_".SalesChannel::WHOLESALE);

        Livewire::test(CartComponent::class)
            ->assertSee('Wholesale minimum for this product is 50')
            ->assertSee('Add 20 more');
    }

    public function test_the_cart_says_nothing_when_the_lot_meets_the_minimum(): void
    {
        $this->actAsWholesaleBuyer();
        [$first, $second] = $this->product->variations()->orderBy('id')->take(2)->get()->all();

        app(CartSessionService::class)->addWholesaleLot($this->product->id, [$first->id => 30, $second->id => 20]);

        Livewire::test(CartComponent::class)
            ->assertDontSee('Wholesale minimum for this product');
    }
}
