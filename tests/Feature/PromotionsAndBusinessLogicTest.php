<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\StoreSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Cart\CouponService;
use App\Services\Promotions\FlashSaleLifecycleService;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionsAndBusinessLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
    }

    public function test_banner_active_scope_filters_by_schedule_dates(): void
    {
        // Active banner with no date bounds
        Banner::create([
            'title' => 'Always Active',
            'desktop_image_url' => 'https://example.com/banner1.jpg',
            'position' => 'hero_slider',
            'is_active' => true,
        ]);

        // Future banner (should be excluded)
        Banner::create([
            'title' => 'Future Banner',
            'desktop_image_url' => 'https://example.com/banner2.jpg',
            'position' => 'hero_slider',
            'start_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        // Expired banner (should be excluded)
        Banner::create([
            'title' => 'Expired Banner',
            'desktop_image_url' => 'https://example.com/banner3.jpg',
            'position' => 'hero_slider',
            'end_at' => now()->subDay(),
            'is_active' => true,
        ]);

        // Inactive banner (should be excluded)
        Banner::create([
            'title' => 'Disabled Banner',
            'desktop_image_url' => 'https://example.com/banner4.jpg',
            'position' => 'hero_slider',
            'is_active' => false,
        ]);

        $activeBanners = Banner::active()->get();
        $this->assertCount(1, $activeBanners);
        $this->assertEquals('Always Active', $activeBanners->first()->title);
    }

    public function test_coupon_percentage_discount_calculation_with_cap(): void
    {
        $coupon = Coupon::create([
            'code' => 'SAVE10',
            'name' => '10% Off',
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 10.00,
            'min_order_amount' => 500.00,
            'max_discount_amount' => 200.00,
            'is_active' => true,
        ]);

        // Subtotal below minimum threshold
        $validation = $coupon->validateForSubtotal(400.00);
        $this->assertFalse($validation['valid']);

        // Subtotal qualifies: 10% of 1000 = 100 (below cap of 200)
        $discount1 = $coupon->calculateDiscount(1000.00);
        $this->assertEquals(100.00, $discount1);

        // Subtotal qualifies: 10% of 5000 = 500, but capped at 200
        $discount2 = $coupon->calculateDiscount(5000.00);
        $this->assertEquals(200.00, $discount2);
    }

    public function test_coupon_fixed_discount_calculation(): void
    {
        $coupon = Coupon::create([
            'code' => 'FLAT150',
            'name' => 'Flat 150 Off',
            'discount_type' => Coupon::TYPE_FIXED,
            'discount_value' => 150.00,
            'min_order_amount' => 1000.00,
            'is_active' => true,
        ]);

        $discount = $coupon->calculateDiscount(1500.00);
        $this->assertEquals(150.00, $discount);
    }

    public function test_coupon_service_applies_and_records_usage(): void
    {
        $userRole = Role::where('slug', 'retail_buyer')->firstOrFail();
        $user = User::create([
            'role_id' => $userRole->id,
            'name' => 'John Buyer',
            'email' => 'john@buyer.com',
            'password' => bcrypt('password'),
        ]);

        $coupon = Coupon::create([
            'code' => 'SINGLEUSE',
            'name' => 'Single Use Coupon',
            'discount_type' => Coupon::TYPE_FIXED,
            'discount_value' => 100.00,
            'min_order_amount' => 500.00,
            'usage_limit_per_user' => 1,
            'is_active' => true,
        ]);

        $couponService = app(CouponService::class);

        // First application: valid
        $result = $couponService->applyCoupon('SINGLEUSE', 1000.00, $user->id);
        $this->assertTrue($result['valid']);
        $this->assertEquals(100.00, $result['discount']);

        // Record usage
        $couponService->recordUsage($coupon, $user->id, null, 100.00);
        $this->assertEquals(1, $coupon->fresh()->used_count);

        // Second application should fail because user limit reached
        $secondResult = $couponService->applyCoupon('SINGLEUSE', 1000.00, $user->id);
        $this->assertFalse($secondResult['valid']);
    }

    public function test_product_discount_percentage_and_flash_sale_pricing(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Seller Test',
            'email' => 'seller@test.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $pieceUnit = Unit::where('symbol', 'pc')->firstOrFail();
        $category = Category::create(['name' => 'Test Cat', 'slug' => 'test-cat']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $pieceUnit->id,
            'title' => 'Test OLED TV',
            'slug' => 'test-oled-tv',
            'regular_price' => 100000.00,
            'buy_price' => 60000.00,
            'is_active' => true,
        ]);
        $this->approveProducts($product);

        // Retail price
        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 80000.00,
        ]);

        $campaign = FlashSale::factory()->live()->create();
        FlashSaleProduct::factory()->create([
            'flash_sale_id' => $campaign->id,
            'product_id' => $product->id,
            'discount_type' => FlashSaleDiscountType::FixedPrice,
            'discount_value' => 75000.00,
        ]);

        // While the campaign is live, the effective price is the campaign price (75,000)
        $this->assertEquals(75000.00, $product->effective_selling_price);

        // Savings should be 100,000 - 75,000 = 25,000
        $this->assertEquals(25000.00, $product->savings_amount);

        // Discount percentage should be 25%
        $this->assertEquals(25, $product->discount_percentage);

        // Turn the campaign off
        app(FlashSaleLifecycleService::class)->endNow($campaign);
        $product->refresh();

        // Effective price reverts to regular retail price (80,000)
        $this->assertEquals(80000.00, $product->effective_selling_price);
        $this->assertEquals(20, $product->discount_percentage);
    }

    public function test_cart_session_service_applies_and_deducts_coupon(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::create([
            'role_id' => $sellerRole->id,
            'name' => 'Seller Cart',
            'email' => 'seller-cart@test.com',
            'password' => bcrypt('password'),
        ]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => $seller->name, 'store_slug' => Str::slug($seller->name).'-'.$seller->id, 'is_approved' => true]);

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $pieceUnit = Unit::where('symbol', 'pc')->firstOrFail();
        $category = Category::create(['name' => 'Audio', 'slug' => 'audio']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retailChannel->id,
            'unit_id' => $pieceUnit->id,
            'title' => 'Headphones',
            'slug' => 'headphones',
            'buy_price' => 500.00,
            'is_active' => true,
        ]);
        $this->approveProducts($product);

        ProductPrice::create([
            'product_id' => $product->id,
            'sales_channel_id' => $retailChannel->id,
            'min_quantity' => 1,
            'unit_price' => 1000.00,
        ]);

        Coupon::create([
            'code' => 'FIFTYOFF',
            'name' => '৳50 Off',
            'discount_type' => Coupon::TYPE_FIXED,
            'discount_value' => 50.00,
            'min_order_amount' => 500.00,
            'is_active' => true,
        ]);

        $cart = app(CartSessionService::class);
        $cart->clear();

        // Add 2 headphones = 2000.00 subtotal
        $cart->addItem(
            productId: $product->id,
            variationId: null,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        $this->assertEquals(2000.00, $cart->getSubtotal());

        // Apply coupon
        $res = $cart->applyCoupon('FIFTYOFF');
        $this->assertTrue($res['valid']);

        $this->assertEquals(50.00, $cart->getDiscountAmount());
        $this->assertEquals(1950.00, $cart->getSubtotalAfterDiscount());

        // Remove coupon
        $cart->removeCoupon();
        $this->assertEquals(0.00, $cart->getDiscountAmount());
        $this->assertEquals(2000.00, $cart->getSubtotalAfterDiscount());
    }

    public function test_store_setting_cache_and_retrieval(): void
    {
        StoreSetting::set('support_hotline', '16222', 'general');

        $this->assertEquals('16222', StoreSetting::get('support_hotline'));
        $this->assertEquals('default_value', StoreSetting::get('non_existent', 'default_value'));
    }
}
