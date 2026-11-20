<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ORD-3: the order and everything recorded with it (status, coupon use, saved address) commit together, and a
 * coupon can't be used beyond its limit by buyers checking out at the same moment.
 * ORD-4: a seller suspended after the buyer filled the cart can't receive the order.
 */
class CheckoutAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ProductVariation $variation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->variation = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail()->variations()->firstOrFail();
    }

    private function checkoutWithCart(?string $couponCode = null): Testable
    {
        $this->actingAs($this->customer);
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $this->variation->product_id, variationId: $this->variation->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        $component = Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Atomic Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'));

        if ($couponCode) {
            $component->set('checkoutCouponCode', $couponCode)->call('applyCheckoutCoupon')->assertSet('couponError', '');
        }

        return $component;
    }

    public function test_the_last_coupon_use_taken_by_another_buyer_mid_checkout_is_refused_and_nothing_is_saved(): void
    {
        $coupon = Coupon::create([
            'code' => 'LASTONE', 'name' => 'Last one', 'discount_type' => 'fixed', 'discount_value' => 10,
            'min_order_amount' => 0, 'usage_limit_total' => 1, 'used_count' => 0, 'is_active' => true,
        ]);
        $component = $this->checkoutWithCart('LASTONE');
        $ordersBefore = Order::count();
        $stockBefore = (int) $this->variation->fresh()->stock_quantity;

        // Another buyer's order uses the coupon while this order is being written
        Order::creating(function () use ($coupon): void {
            Coupon::whereKey($coupon->id)->update(['used_count' => 1]);
        });

        $component->call('placeOrder')->assertSet('errorMessage', fn (string $message): bool => str_contains($message, 'coupon'));

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame(0, CouponUsage::count());
        // The simulated buyer's update ran inside this order's transaction, so it rolled back with it; the point is
        // that the coupon never goes past its limit.
        $this->assertLessThanOrEqual(1, $coupon->fresh()->used_count);
        $this->assertSame($stockBefore, (int) $this->variation->fresh()->stock_quantity);
    }

    public function test_a_seller_suspended_after_the_cart_was_filled_gets_no_order(): void
    {
        $component = $this->checkoutWithCart();
        $ordersBefore = Order::count();
        $this->variation->product->seller->sellerProfile->update(['monetization_status' => 'suspended']);

        $component->call('placeOrder')->assertSet('errorMessage', fn (string $message): bool => $message !== '');

        $this->assertSame($ordersBefore, Order::count());
    }
}
