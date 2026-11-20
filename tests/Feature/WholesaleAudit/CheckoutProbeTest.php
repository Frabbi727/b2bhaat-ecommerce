<?php

declare(strict_types=1);

namespace Tests\Feature\WholesaleAudit;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductCatalog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wholesale audit 2026-11-14, cart and checkout integrity probes (report E part 1).
 * Demo retail price ৳250; retail customer customer@retail.com.
 */
#[Group('wholesale-audit')]
class CheckoutProbeTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $customer;

    private CartSessionService $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($this->customer);
        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $this->cart = app(CartSessionService::class);
        $this->cart->clear();
    }

    private function checkoutForm(): Testable
    {
        return Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Retail Customer')
            ->set('recipientPhone', '01711000000')
            ->set('streetAddress', 'House 1, Road 2, Dhanmondi')
            ->set('city', 'Dhaka');
    }

    private function latestOrder(): ?Order
    {
        return Order::where('customer_id', $this->customer->id)->latest('id')->with('subOrders.items')->first();
    }

    /**
     * WA-CHK-02 (fixed): the quick-view quantity is a public Livewire property a browser can set to −5.
     * The cart refuses any quantity below 1, so the negative line never lowers the order.
     */
    public function test_wa_chk_02_negative_quick_view_quantity_is_refused(): void
    {
        [$v1, $v2] = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->take(2)->get()->all();

        Livewire::test(ProductCatalog::class)
            ->call('openQuickView', $this->product->id)
            ->set('quickViewVariationId', $v1->id)
            ->set('quickViewQuantity', 10)
            ->call('quickViewAddToCart')
            ->call('openQuickView', $this->product->id)
            ->set('quickViewVariationId', $v2->id)
            ->set('quickViewQuantity', -5)
            ->call('quickViewAddToCart')
            ->assertSet('errorMessage', fn (string $message) => str_contains($message, 'at least 1'));

        $this->assertSame(2500.0, $this->cart->getSubtotal(), 'only the 10 × ৳250 line is in the cart');
        $this->assertCount(1, $this->cart->getItems());
    }

    /**
     * WA-CHK-02 (fixed): every way into the cart goes through one guard, so a zero or negative quantity is refused.
     */
    public function test_wa_chk_02_cart_refuses_a_quantity_below_one(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();

        $refused = 0;
        foreach ([0, -5] as $quantity) {
            try {
                $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, $quantity);
            } catch (\DomainException $e) {
                $this->assertStringContainsString('at least 1', $e->getMessage());
                $refused++;
            }
        }

        $this->assertSame(2, $refused);
        $this->assertSame([], $this->cart->getItems());
    }

    /**
     * WA-CHK-01 (fixed): appliedCoupon is display-only. The browser can no longer send a coupon id or code of its own.
     */
    public function test_wa_chk_01_browser_cannot_set_the_applied_coupon(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->checkoutForm()->set('appliedCoupon', ['id' => 1, 'code' => 'ONCEONLY', 'name' => 'x', 'discount' => 0]);
    }

    /**
     * WA-CHK-01 (fixed): the coupon comes from the server-side cart only, and its use is counted on that same coupon,
     * so a one-use coupon gives its discount once.
     */
    public function test_wa_chk_01_one_use_coupon_is_given_once_and_counted_on_itself(): void
    {
        $limited = Coupon::create(['code' => 'ONCEONLY', 'name' => 'One per customer', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 200, 'min_order_amount' => 0, 'usage_limit_per_user' => 1, 'usage_limit_total' => 1, 'is_active' => true]);
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();

        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $this->assertTrue($this->cart->applyCoupon('ONCEONLY', $this->customer->id)['valid']);
        $this->checkoutForm()->call('placeOrder')->assertSet('errorMessage', '');

        $order = $this->latestOrder();
        $this->assertSame(200.0, (float) $order->discount_amount);
        $this->assertSame($limited->id, (int) $order->coupon_id);
        $this->assertSame(1, CouponUsage::where('coupon_id', $limited->id)->count());
        $this->assertSame(1, (int) $limited->fresh()->used_count);

        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $this->assertFalse($this->cart->applyCoupon('ONCEONLY', $this->customer->id)['valid'], 'second use is refused');
    }

    /**
     * WA-CHK-01 (fixed): a coupon used up by someone else while the buyer is on checkout is checked again under a row
     * lock. The order stops with a message instead of silently charging full price; pressing again orders without it.
     */
    public function test_wa_chk_01_coupon_used_up_during_checkout_stops_the_order(): void
    {
        $limited = Coupon::create(['code' => 'LASTONE', 'name' => 'Last one', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 200, 'min_order_amount' => 0, 'usage_limit_total' => 1, 'is_active' => true]);
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $this->assertTrue($this->cart->applyCoupon('LASTONE', $this->customer->id)['valid']);
        $form = $this->checkoutForm();

        $limited->increment('used_count');
        $ordersBefore = Order::count();

        $form->call('placeOrder')->assertSet('errorMessage', fn (string $m) => str_contains($m, 'cannot be redeemed'));
        $this->assertSame($ordersBefore, Order::count());

        $form->call('placeOrder')->assertSet('errorMessage', '');
        $order = $this->latestOrder();
        $this->assertNull($order->coupon_id);
        $this->assertSame(0.0, (float) $order->discount_amount);
        $this->assertSame(1, (int) $limited->fresh()->used_count);
    }

    /**
     * WA-SEC-01 (fixed): after 10 wrong codes in 10 minutes, coupon attempts are refused, even with a real code.
     */
    public function test_wa_sec_01_wrong_coupon_codes_are_throttled(): void
    {
        Coupon::create(['code' => 'REAL10', 'name' => 'Real', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 10, 'min_order_amount' => 0, 'is_active' => true]);
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertFalse($this->cart->applyCoupon("GUESS{$attempt}", $this->customer->id)['valid']);
        }

        $result = $this->cart->applyCoupon('REAL10', $this->customer->id);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Too many', $result['message']);
    }

    /**
     * Verified correct: an approved price change that lands while the buyer is on checkout stops the order and
     * re-prices the cart (the message wording is WA-PRC-06).
     */
    public function test_verified_price_change_between_cart_and_place_order_stops_checkout(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $form = $this->checkoutForm();

        ProductPrice::where('product_id', $this->product->id)
            ->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::RETAIL))
            ->update(['unit_price' => 300.00]);

        $ordersBefore = Order::count();
        $form->call('placeOrder')->assertSet('errorMessage', fn (string $m) => str_contains($m, 'Prices changed'));
        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame(600.0, $this->cart->getSubtotal());

        $form->call('placeOrder')->assertSet('errorMessage', '');
        $this->assertSame(300.0, (float) $this->latestOrder()->subOrders->sole()->items->sole()->unit_price);
    }

    /**
     * WA-CHK-03 (fixed): a second Place order that arrives while the first is still running is refused. Both requests
     * carry the same page token, and only one of them can claim it.
     */
    public function test_wa_chk_03_place_order_while_the_same_page_is_already_ordering_is_refused(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $form = $this->checkoutForm();

        $inFlight = Cache::lock(CheckoutComponent::orderLockKey($this->customer->id, $form->get('checkoutToken')), 60);
        $this->assertTrue($inFlight->get(), 'the first request holds the page token');

        $ordersBefore = Order::count();
        $form->call('placeOrder')->assertSet('errorMessage', fn (string $m) => str_contains($m, 'already being placed'));

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame((int) $variation->stock_quantity, (int) $variation->fresh()->stock_quantity, 'no stock taken');
    }

    /**
     * WA-CHK-03: a failed attempt gives the page token back, so the buyer can fix the problem and press Place order again.
     */
    public function test_wa_chk_03_failed_place_order_can_be_retried_on_the_same_page(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);
        $coupon = Coupon::create(['code' => 'RETRY10', 'name' => 'Retry', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 10, 'min_order_amount' => 0, 'usage_limit_per_user' => 5, 'is_active' => true]);
        $this->assertTrue($this->cart->applyCoupon('RETRY10', $this->customer->id)['valid']);
        $form = $this->checkoutForm();
        $ordersBefore = Order::count();

        // The coupon is switched off while the buyer is on checkout: this attempt fails inside the order step
        $coupon->update(['is_active' => false]);
        $form->call('placeOrder')->assertSet('errorMessage', fn (string $m) => str_contains($m, 'cannot be redeemed'));
        $this->assertSame($ordersBefore, Order::count());

        $form->call('placeOrder')->assertSet('errorMessage', '');
        $this->assertSame($ordersBefore + 1, Order::count());
    }

    /**
     * Verified correct for sequential requests: a second Place order on the same page finds the cart empty.
     * Two requests at the same moment are not covered (see WA-CHK-03).
     */
    public function test_verified_second_place_order_after_success_places_nothing(): void
    {
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();
        $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);

        $ordersBefore = Order::count();
        $form = $this->checkoutForm();
        $form->call('placeOrder')->assertSet('errorMessage', '');
        $form->call('placeOrder');

        $this->assertSame($ordersBefore + 1, Order::count());
        $this->assertSame(1, OrderItem::whereIn('sub_order_id', $this->latestOrder()->subOrders->pluck('id'))->count());
    }
}
