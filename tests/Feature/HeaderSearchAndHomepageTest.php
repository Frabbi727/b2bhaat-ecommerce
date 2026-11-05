<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\HeaderSearch;
use App\Livewire\Storefront\ProductCatalog;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class HeaderSearchAndHomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
    }

    public function test_header_search_autocompletes_matching_products_and_categories(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Tech Seller',
            'email' => 'tech@seller.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();

        $cat = Category::create([
            'name' => 'Smart TVs & Monitors',
            'slug' => 'smart-tvs-monitors',
            'is_active' => true,
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Haier 55 Inch Mini LED Google TV',
            'slug' => 'haier-55-inch-mini-led-google-tv',
            'sku' => 'H55M80GUX',
            'model_number' => 'H55M80GUX',
            'regular_price' => 115000.00,
            'buy_price' => 70000.00,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 84900.00,
        ]);

        Livewire::test(HeaderSearch::class)
            ->set('query', 'Mini LED')
            ->assertSet('isOpen', true)
            ->assertSee('Haier 55 Inch Mini LED Google TV')
            ->assertSee('84,900')
            ->call('submitSearch')
            ->assertRedirect(route('catalog.index', ['search' => 'Mini LED']));
    }

    public function test_homepage_renders_hero_banners_and_flash_sales(): void
    {
        Banner::create([
            'title' => 'Mega Electronics Bonanza',
            'subtitle' => 'Save up to 40% on flagship appliances',
            'desktop_image_url' => 'https://example.com/banner.jpg',
            'position' => 'hero_slider',
            'is_active' => true,
        ]);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Banner Seller',
            'email' => 'banner@seller.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();
        $cat = Category::create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);

        Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Flash Sale Phone',
            'slug' => 'flash-sale-phone',
            'regular_price' => 50000.00,
            'buy_price' => 30000.00,
            'is_flash_sale' => true,
            'flash_sale_price' => 39999.00,
            'flash_sale_end' => now()->addDays(2),
            'is_active' => true,
        ]);

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Mega Electronics Bonanza');
        $response->assertSee('Flash Sale');
        $response->assertSee('Flash Sale Phone');
        $response->assertSee('39,999');
    }

    public function test_catalog_sorting_and_quick_cart(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Sort Seller',
            'email' => 'sort@seller.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();
        $cat = Category::create(['name' => 'Accessories', 'slug' => 'accessories', 'is_active' => true]);

        $itemA = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Budget Cable',
            'slug' => 'budget-cable',
            'regular_price' => 200.00,
            'buy_price' => 50.00,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $itemA->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 150.00,
        ]);

        $itemB = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Premium Charger',
            'slug' => 'premium-charger',
            'regular_price' => 1500.00,
            'buy_price' => 500.00,
            'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $itemB->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 1200.00,
        ]);

        Livewire::test(ProductCatalog::class)
            ->set('sort', 'price_asc')
            ->assertSeeInOrder(['Budget Cable', 'Premium Charger'])
            ->call('quickAddToCart', $itemA->id)
            ->assertDispatched('cartUpdated')
            ->assertSee('Budget Cable')
            ->assertSee('to your cart');
    }

    public function test_quick_view_modal_opens_and_allows_instant_add_to_cart_and_buy_now(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Gadget Hub',
            'email' => 'gadget@hub.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $channel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $unit = Unit::where('symbol', 'pc')->firstOrFail();

        $cat = Category::create([
            'name' => 'Wearables',
            'slug' => 'wearables',
            'is_active' => true,
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $cat->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Fitness Smart Band 8',
            'slug' => 'fitness-smart-band-8',
            'sku' => 'BAND8-BLK',
            'model_number' => 'SB-800',
            'regular_price' => 4500.00,
            'buy_price' => 2200.00,
            'warranty_period' => '1 Year Official Warranty',
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'min_quantity' => 1,
            'unit_price' => 3800.00,
        ]);

        Livewire::test(ProductCatalog::class)
            ->assertSet('showQuickView', false)
            ->call('openQuickView', $product->id)
            ->assertSet('showQuickView', true)
            ->assertSee('Fitness Smart Band 8')
            ->assertSee('Special Selling Price')
            ->assertSee('৳3,800.00')
            ->assertSee('Save ৳700')
            ->call('updateQuickViewQuantity', 1)
            ->assertSet('quickViewQuantity', 2)
            ->call('quickViewAddToCart')
            ->assertDispatched('cartUpdated')
            ->assertSet('showQuickView', false)
            ->assertSee('Added 2x');
    }
}
