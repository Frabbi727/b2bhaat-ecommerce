<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDetailPickabooStandardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
    }

    public function test_product_detail_renders_pickaboo_specifications_and_pricing(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Haier Official Store',
            'email' => 'haier@test.com',
            'password' => bcrypt('password'),
        ]);

        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Haier Official Direct',
            'store_slug' => 'haier-official-direct',
            'is_approved' => true,
        ]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();
        $brand = Brand::create(['name' => 'Haier', 'slug' => 'haier', 'is_active' => true]);
        $cat = Category::create(['name' => 'Smart TVs', 'slug' => 'smart-tvs', 'is_active' => true]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'brand_id' => $brand->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Haier 55 Inch Mini LED 4K Google TV (H55M80GUX)',
            'slug' => 'haier-55-inch-mini-led-4k-uhd-google-tv-h55m80gux',
            'model_number' => 'H55M80GUX',
            'sku' => 'H55M80GUX-55',
            'regular_price' => 115000.00,
            'buy_price' => 70000.00,
            'is_flash_sale' => true,
            'flash_sale_price' => 84900.00,
            'flash_sale_end' => now()->addDays(5),
            'warranty_period' => '4 Years Panel Warranty',
            'specifications' => [
                'Display' => [
                    'Resolution' => '4K Ultra HD (3840 x 2160)',
                    'Refresh Rate' => '120Hz Native',
                ],
                'Audio' => [
                    'Sound Processing' => 'Dolby Atmos 30W',
                ],
            ],
            'features' => [
                'Mini LED Backlight with 500+ dimming zones',
                'Google TV with Hands-free Voice Search',
            ],
            'is_active' => true,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/tv1.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => 'https://example.com/tv2.jpg',
            'is_primary' => false,
            'sort_order' => 2,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 88900.00,
        ]);

        $var = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'H55M80GUX-55',
            'buy_price' => 70000.00,
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Haier 55 Inch Mini LED 4K Google TV')
            ->assertSee('H55M80GUX')
            ->assertSee('84,900')
            ->assertSee('115,000')
            ->assertSee('Save ৳30,100')
            ->assertSee('4 Years Panel Warranty')
            ->assertSee('4K Ultra HD (3840 x 2160)')
            ->assertSee('Dolby Atmos 30W')
            ->call('selectImage', 1)
            ->assertSet('activeImageIndex', 1);

        // Verify recently viewed tracking
        $recent = session()->get('recently_viewed_products');
        $this->assertContains($product->id, $recent);
    }

    public function test_buy_now_adds_item_and_redirects_to_checkout(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Audio Store',
            'email' => 'audio@test.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();
        $cat = Category::create(['name' => 'Audio', 'slug' => 'audio', 'is_active' => true]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Sony Headphones',
            'slug' => 'sony-headphones',
            'regular_price' => 40000.00,
            'buy_price' => 25000.00,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 32000.00,
        ]);

        $var = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'SONY-WH-BLK',
            'buy_price' => 25000.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $cart = app(CartSessionService::class);
        $cart->clear();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('selectedVariationId', $var->id)
            ->set('retailQuantity', 1)
            ->call('buyNowRetail')
            ->assertRedirect(route('checkout.index'));

        $this->assertEquals(1, $cart->getItemCount());
        $this->assertEquals(32000.00, $cart->getSubtotal());
    }

    public function test_bundle_purchase_adds_both_products(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Bundle Seller',
            'email' => 'bundle@test.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();
        $cat = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $main = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Main TV',
            'slug' => 'main-tv',
            'regular_price' => 50000.00,
            'buy_price' => 30000.00,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $main->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 45000.00,
        ]);
        ProductVariation::create([
            'product_id' => $main->id,
            'sku' => 'TV-VAR-1',
            'buy_price' => 30000.00,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $accessory = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'HDMI 2.1 Cable',
            'slug' => 'hdmi-21-cable',
            'regular_price' => 2000.00,
            'buy_price' => 500.00,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $accessory->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 1500.00,
        ]);

        $cart = app(CartSessionService::class);
        $cart->clear();

        Livewire::test(ProductDetail::class, ['slug' => $main->slug])
            ->call('addBundleToCart', $accessory->id)
            ->assertDispatched('cartUpdated');

        $this->assertEquals(2, $cart->getItemCount());
        $this->assertEquals(46500.00, $cart->getSubtotal());
    }
}
