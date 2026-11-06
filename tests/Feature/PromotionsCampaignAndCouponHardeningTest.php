<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Banner;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Cart\CouponService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromotionsCampaignAndCouponHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->couponService = app(CouponService::class);
    }

    public function test_sync_active_campaigns_command_retires_expired_banners(): void
    {
        // Expired banner
        $expiredBanner = Banner::create([
            'title' => 'Expired Summer Promo',
            'desktop_image_url' => 'https://example.com/banner.jpg',
            'position' => 'hero_slider',
            'is_active' => true,
            'end_at' => now()->subHour(),
        ]);

        // Future active banner
        $activeBanner = Banner::create([
            'title' => 'Active Mega Sale',
            'desktop_image_url' => 'https://example.com/banner-active.jpg',
            'position' => 'hero_slider',
            'is_active' => true,
            'end_at' => now()->addDays(5),
        ]);

        $this->artisan('promotions:sync-active-campaigns')
            ->assertExitCode(0);

        $this->assertFalse((bool) $expiredBanner->fresh()->is_active);
        $this->assertTrue((bool) $activeBanner->fresh()->is_active);
    }

    public function test_sync_active_campaigns_command_deactivates_expired_or_exhausted_coupons(): void
    {
        // 1. Expired by date
        $expiredCoupon = Coupon::create([
            'code' => 'EXPIRED20',
            'name' => 'Expired Discount',
            'discount_type' => 'percentage',
            'discount_value' => 20.00,
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        // 2. Fully exhausted usage limit
        $exhaustedCoupon = Coupon::create([
            'code' => 'EXHAUSTED50',
            'name' => 'Limited Claims',
            'discount_type' => 'fixed',
            'discount_value' => 50.00,
            'usage_limit_total' => 10,
            'used_count' => 10,
            'is_active' => true,
        ]);

        // 3. Healthy active coupon
        $healthyCoupon = Coupon::create([
            'code' => 'HEALTHY10',
            'name' => 'Standard Discount',
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'usage_limit_total' => 100,
            'used_count' => 5,
            'expires_at' => now()->addMonths(1),
            'is_active' => true,
        ]);

        $this->artisan('promotions:sync-active-campaigns')
            ->assertExitCode(0);

        $this->assertFalse((bool) $expiredCoupon->fresh()->is_active);
        $this->assertFalse((bool) $exhaustedCoupon->fresh()->is_active);
        $this->assertTrue((bool) $healthyCoupon->fresh()->is_active);
    }

    public function test_coupon_validation_respects_minimum_order_threshold_and_caps(): void
    {
        $coupon = Coupon::create([
            'code' => 'TIER20',
            'name' => '20% Capped Discount',
            'discount_type' => 'percentage',
            'discount_value' => 20.00,
            'min_order_amount' => 2000.00,
            'max_discount_amount' => 1000.00,
            'is_active' => true,
        ]);

        // Below minimum subtotal (৳1500)
        $resLow = $this->couponService->applyCoupon('TIER20', 1500.00);
        $this->assertFalse($resLow['valid']);
        $this->assertStringContainsString('requires a minimum subtotal of ৳2,000.00', $resLow['message']);

        // Exactly at minimum subtotal (৳2000 => 20% is ৳400)
        $resExact = $this->couponService->applyCoupon('TIER20', 2000.00);
        $this->assertTrue($resExact['valid']);
        $this->assertEquals(400.00, $resExact['discount']);

        // High subtotal where 20% would be ৳2400, but ceiling is ৳1000
        $resHigh = $this->couponService->applyCoupon('TIER20', 12000.00);
        $this->assertTrue($resHigh['valid']);
        $this->assertEquals(1000.00, $resHigh['discount']);
    }

    public function test_checkout_revalidates_applied_coupon_and_prevents_stale_or_exhausted_redemption(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($customer);

        $coupon = Coupon::create([
            'code' => 'SINGLEUSE100',
            'name' => 'Single Use Per Customer',
            'discount_type' => 'fixed',
            'discount_value' => 100.00,
            'usage_limit_per_user' => 1,
            'is_active' => true,
        ]);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        // Put item in cart
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem($product->id, $variation->id, SalesChannel::RETAIL, 2);

        // Mount checkout component and apply coupon via component
        $checkout = Livewire::test(CheckoutComponent::class)
            ->set('checkoutCouponCode', 'SINGLEUSE100')
            ->call('applyCheckoutCoupon')
            ->assertSet('couponError', '');

        // Now simulate another completed order consuming this user's single claim
        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $customer->id,
            'order_id' => null,
            'discount_amount' => 100.00,
        ]);

        // Place order: checkout re-validation must detect exhausted limit and abort
        $checkout->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->set('recipientName', 'Customer Name')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'Dhanmondi 27')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1209')
            ->set('deliveryZone', 'inside_dhaka')
            ->call('placeOrder')
            ->assertSet('errorMessage', 'Promotional coupon cannot be redeemed: You have already utilized this coupon code.');

        $this->assertEquals(0, Order::where('customer_id', $customer->id)->where('coupon_id', $coupon->id)->count());
    }

    public function test_banner_positions_and_scopes_filter_by_schedule_and_placement(): void
    {
        // Clear existing banners for isolated test
        Banner::query()->delete();

        Banner::create([
            'title' => 'Top Hero Slider 1',
            'desktop_image_url' => 'https://example.com/h1.jpg',
            'position' => 'hero_slider',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Mid Page Ribbon',
            'desktop_image_url' => 'https://example.com/promo.jpg',
            'position' => 'promo_strip',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Future Hidden Ribbon',
            'desktop_image_url' => 'https://example.com/future.jpg',
            'position' => 'promo_strip',
            'start_at' => now()->addDays(2),
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $heroBanners = Banner::active()->forPosition('hero_slider')->get();
        $promoBanners = Banner::active()->forPosition('promo_strip')->get();

        $this->assertCount(1, $heroBanners);
        $this->assertEquals('Top Hero Slider 1', $heroBanners->first()->title);

        $this->assertCount(1, $promoBanners);
        $this->assertEquals('Mid Page Ribbon', $promoBanners->first()->title);
    }
}
