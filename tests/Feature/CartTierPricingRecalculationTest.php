<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTierPricingRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected SalesChannel $retailChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);

        $this->retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        $seller = User::factory()->create();
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Demo Factory Store',
            'store_slug' => 'demo-factory-store',
            'is_approved' => true,
        ]);

        $category = Category::create([
            'name' => 'Gadgets',
            'slug' => 'gadgets',
            'is_active' => true,
        ]);

        $unit = Unit::where('symbol', 'pc')->firstOrFail();

        $this->product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $this->retailChannel->id,
            'unit_id' => $unit->id,
            'title' => 'Tiered Electronics Gadget',
            'slug' => 'tiered-electronics-gadget',
            'description' => 'A gadget with volume discounts',
            'is_active' => true,
            'is_approved' => true,
        ]);
        $this->approveProducts($this->product);

        // Tier 1: 1 - 99 pcs @ 100 Tk
        ProductPrice::create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->retailChannel->id,
            'min_quantity' => 1,
            'max_quantity' => 99,
            'unit_price' => 100.00,
        ]);

        // Tier 2: 100 - 200 pcs @ 80 Tk
        ProductPrice::create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->retailChannel->id,
            'min_quantity' => 100,
            'max_quantity' => 200,
            'unit_price' => 80.00,
        ]);

        // Tier 3: 201+ pcs @ 70 Tk
        ProductPrice::create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->retailChannel->id,
            'min_quantity' => 201,
            'max_quantity' => null,
            'unit_price' => 70.00,
        ]);
    }

    public function test_cart_item_unit_price_and_subtotal_update_automatically_when_quantity_reaches_tier(): void
    {
        $cart = app(CartSessionService::class);
        $cart->clear();

        // 1. User adds 1 pc -> Tier 1 (100 Tk)
        $cart->addItem(
            productId: $this->product->id,
            variationId: null,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        // Simple products are sold through their default variation (PRA-011)
        $key = "{$this->product->id}_{$this->product->variations()->value('id')}_retail";
        $items = $cart->getItems();
        $this->assertEquals(100.00, $items[$key]['unit_price']);
        $this->assertEquals(100.00, $cart->getSubtotal());

        // 2. User updates quantity in cart to 150 pcs -> Falls into Tier 2 (100 - 200 pcs @ 80 Tk)
        $cart->updateQuantity($key, 150);

        $items = $cart->getItems();
        $this->assertEquals(150, $items[$key]['quantity']);
        $this->assertEquals(80.00, $items[$key]['unit_price']);
        // 150 * 80 = 12,000 Tk
        $this->assertEquals(12000.00, $cart->getSubtotal());

        // 3. User updates quantity to 250 pcs -> Falls into Tier 3 (201+ pcs @ 70 Tk)
        $cart->updateQuantity($key, 250);

        $items = $cart->getItems();
        $this->assertEquals(250, $items[$key]['quantity']);
        $this->assertEquals(70.00, $items[$key]['unit_price']);
        // 250 * 70 = 17,500 Tk
        $this->assertEquals(17500.00, $cart->getSubtotal());

        // 4. User drops quantity back to 5 pcs -> Reverts to Tier 1 (100 Tk)
        $cart->updateQuantity($key, 5);

        $items = $cart->getItems();
        $this->assertEquals(5, $items[$key]['quantity']);
        $this->assertEquals(100.00, $items[$key]['unit_price']);
        // 5 * 100 = 500 Tk
        $this->assertEquals(500.00, $cart->getSubtotal());
    }

    public function test_adding_more_items_to_existing_cart_item_recalculates_tier_on_combined_quantity(): void
    {
        $cart = app(CartSessionService::class);
        $cart->clear();

        // Add 50 pcs (Tier 1 @ 100 Tk)
        $cart->addItem(
            productId: $this->product->id,
            variationId: null,
            channelSlug: SalesChannel::RETAIL,
            quantity: 50
        );

        // Simple products are sold through their default variation (PRA-011)
        $key = "{$this->product->id}_{$this->product->variations()->value('id')}_retail";
        $this->assertEquals(100.00, $cart->getItems()[$key]['unit_price']);

        // Add 60 more pcs of the same product -> Total becomes 110 pcs (Qualifies for Tier 2 @ 80 Tk)
        $cart->addItem(
            productId: $this->product->id,
            variationId: null,
            channelSlug: SalesChannel::RETAIL,
            quantity: 60
        );

        $items = $cart->getItems();
        $this->assertEquals(110, $items[$key]['quantity']);
        $this->assertEquals(80.00, $items[$key]['unit_price']);
        // 110 * 80 = 8,800 Tk
        $this->assertEquals(8800.00, $cart->getSubtotal());
    }
}
