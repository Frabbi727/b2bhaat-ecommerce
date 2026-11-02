<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Banners\Pages\ListBanners;
use App\Filament\Admin\Resources\Coupons\Pages\ListCoupons;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CartCouponCheckoutAndPromotionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $buyerUser;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->adminUser = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->buyerUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->product = Product::where('slug', 'haier-55-inch-mini-led-4k-uhd-google-tv-h55m80gux')->first()
            ?? Product::firstOrFail();
    }

    public function test_can_apply_and_remove_coupon_on_cart(): void
    {
        $this->actingAs($this->buyerUser);

        $cart = app(CartSessionService::class);
        $cart->addItem(
            productId: $this->product->id,
            variationId: $this->product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        Livewire::test(CartComponent::class)
            ->set('couponCode', 'WELCOME10')
            ->call('applyCoupon')
            ->assertSee('WELCOME10')
            ->assertSee('discount')
            ->call('removeCoupon')
            ->assertDontSee('Coupon \'WELCOME10\' applied');
    }

    public function test_can_move_cart_item_to_wishlist(): void
    {
        $this->actingAs($this->buyerUser);

        $cart = app(CartSessionService::class);
        $cart->addItem(
            productId: $this->product->id,
            variationId: $this->product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        $items = $cart->getItems();
        $itemKey = array_key_first($items);

        Livewire::test(CartComponent::class)
            ->call('moveToWishlist', $itemKey, $this->product->id)
            ->assertSee('Item moved to your wishlist');

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->buyerUser->id,
            'product_id' => $this->product->id,
        ]);

        $this->assertEmpty($cart->getItems());
    }

    public function test_checkout_applies_coupon_and_records_usage(): void
    {
        $this->actingAs($this->buyerUser);

        $cart = app(CartSessionService::class);
        $cart->addItem(
            productId: $this->product->id,
            variationId: $this->product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 2
        );

        $coupon = Coupon::where('code', 'WELCOME10')->firstOrFail();
        $cart->applyCoupon('WELCOME10', $this->buyerUser->id);

        $subtotal = $cart->getSubtotal();
        $discount = $cart->getDiscountAmount();
        $this->assertGreaterThan(0, $discount);

        $paymentMethod = PaymentMethod::where('slug', 'sslcommerz')->firstOrFail();

        $comp = Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Ahmed')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'Road 12, Sector 4, Uttara')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $paymentMethod->id)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEquals($coupon->id, $order->coupon_id);
        $this->assertEquals('WELCOME10', $order->coupon_code);
        $this->assertEquals($discount, (float) $order->discount_amount);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->buyerUser->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_checkout_with_bkash_requires_transaction_id(): void
    {
        $this->actingAs($this->buyerUser);

        $cart = app(CartSessionService::class);
        $cart->addItem(
            productId: $this->product->id,
            variationId: $this->product->variations->first()?->id,
            channelSlug: SalesChannel::RETAIL,
            quantity: 1
        );

        $bkashMethod = PaymentMethod::where('slug', 'bkash')->firstOrFail();

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Ahmed')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'Road 12, Sector 4, Uttara')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $bkashMethod->id)
            ->set('mfsSenderNumber', '')
            ->set('mfsTransactionId', '')
            ->call('placeOrder')
            ->assertHasErrors(['mfsSenderNumber', 'mfsTransactionId']);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Ahmed')
            ->set('recipientPhone', '01711223344')
            ->set('streetAddress', 'Road 12, Sector 4, Uttara')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1230')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', $bkashMethod->id)
            ->set('mfsSenderNumber', '01711223344')
            ->set('mfsTransactionId', '9J82KD8X')
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEquals('paid', $order->paymentStatus->slug);
        $this->assertEquals('9J82KD8X', $order->shipping_address['mfs_transaction_id']);
    }

    public function test_admin_can_access_banner_and_coupon_filament_resources(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->adminUser);

        Livewire::test(ListBanners::class)
            ->assertSuccessful();

        Livewire::test(ListCoupons::class)
            ->assertSuccessful();
    }
}
