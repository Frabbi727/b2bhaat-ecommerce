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
     * WA-CHK-02: current behaviour; the fix inverts this assertion.
     * The quick-view quantity is a public Livewire property. A browser can set it to −5; the cart accepts a negative
     * line, the order is placed with it and the total drops by 5 × ৳250. Stock is untouched (the reservation skips
     * quantities ≤ 0), so the seller's parcel lists −5 units of an option that was never reserved.
     */
    public function test_wa_chk_02_negative_quick_view_quantity_reduces_the_order(): void
    {
        [$v1, $v2] = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->take(2)->get()->all();
        $stockBefore = (int) $v2->stock_quantity;

        Livewire::test(ProductCatalog::class)
            ->call('openQuickView', $this->product->id)
            ->set('quickViewVariationId', $v1->id)
            ->set('quickViewQuantity', 10)
            ->call('quickViewAddToCart')
            ->call('openQuickView', $this->product->id)
            ->set('quickViewVariationId', $v2->id)
            ->set('quickViewQuantity', -5)
            ->call('quickViewAddToCart');

        $this->assertSame(1250.0, $this->cart->getSubtotal(), 'today: 10 × ৳250 − 5 × ৳250');

        $this->checkoutForm()->call('placeOrder')->assertSet('errorMessage', '');

        $order = $this->latestOrder();
        $this->assertNotNull($order, 'today: the order is placed');
        $negative = $order->subOrders->flatMap->items->firstWhere('variation_id', $v2->id);
        $this->assertSame(-5, (int) $negative->quantity);
        $this->assertSame(1250.0, (float) $order->subOrders->sole()->subtotal);
        $this->assertSame($stockBefore, (int) $v2->fresh()->stock_quantity, 'today: the negative line is skipped by the reservation');
    }

    /**
     * WA-CHK-01: current behaviour; the fix inverts this assertion.
     * appliedCoupon is a public, unlocked property. Checkout validates the coupon by its code but redeems and stores
     * the id the browser sent. Code A (one use per customer) with the id of coupon B gives A's discount every time,
     * while the use is counted on B, so A's limits never fill up.
     */
    public function test_wa_chk_01_coupon_code_and_id_from_browser_bypass_usage_limits(): void
    {
        $limited = Coupon::create(['code' => 'ONCEONLY', 'name' => 'One per customer', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 200, 'min_order_amount' => 0, 'usage_limit_per_user' => 1, 'usage_limit_total' => 1, 'is_active' => true]);
        $decoy = Coupon::create(['code' => 'ANYONE', 'name' => 'Decoy', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 1, 'min_order_amount' => 0, 'usage_limit_per_user' => 100, 'is_active' => true]);
        $variation = ProductVariation::where('product_id', $this->product->id)->orderBy('id')->firstOrFail();

        foreach ([1, 2] as $round) {
            $this->cart->clear();
            $this->cart->addItem($this->product->id, $variation->id, SalesChannel::RETAIL, 2);

            $this->checkoutForm()
                ->set('appliedCoupon', ['id' => $decoy->id, 'code' => 'ONCEONLY', 'name' => 'x', 'discount' => 0])
                ->call('placeOrder')
                ->assertSet('errorMessage', '');

            $order = $this->latestOrder();
            $this->assertSame(200.0, (float) $order->discount_amount, "round {$round}: today the one-use ৳200 discount is given again");
            $this->assertSame($decoy->id, (int) $order->coupon_id);
            $this->assertSame('ONCEONLY', $order->coupon_code);
        }

        $this->assertSame(0, CouponUsage::where('coupon_id', $limited->id)->count(), 'today: no use is recorded on the limited coupon');
        $this->assertSame(0, (int) $limited->fresh()->used_count);
        $this->assertSame(2, (int) $decoy->fresh()->used_count);
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
