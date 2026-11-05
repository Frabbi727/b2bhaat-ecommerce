<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Trust round: every price, promise and policy a shopper sees must be true and come from the admin settings.
 */
class StorefrontTrustTest extends TestCase
{
    use RefreshDatabase;

    private Product $nails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->nails = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $config = app(StoreConfigurationService::class);
        $config->set('delivery_fee_inside_dhaka', 170, 'delivery', 'float');
        $config->set('delivery_fee_sub_dhaka', 220, 'delivery', 'float');
        $config->set('delivery_fee_outside_dhaka', 430, 'delivery', 'float');
        $config->set('free_shipping_threshold', 50000, 'delivery', 'float');
    }

    private function addNailsToCart(): void
    {
        app(CartSessionService::class)->addItem($this->nails->id, $this->nails->variations()->firstOrFail()->id, SalesChannel::RETAIL, 1);
    }

    private function sellerProfile(): SellerProfile
    {
        return SellerProfile::where('user_id', $this->nails->seller_id)->firstOrFail();
    }

    // 1. Delivery fees
    public function test_cart_shows_configured_delivery_fees_and_total_includes_delivery(): void
    {
        $this->addNailsToCart();
        $unitPrice = app(CartSessionService::class)->getSubtotal();

        Livewire::test(CartComponent::class)
            ->assertDontSee('৳70 (Dhaka)')
            ->assertSee('৳170')
            ->assertSee('৳430')
            ->assertSee('৳'.number_format($unitPrice + 170, 2));
    }

    public function test_cart_free_delivery_follows_the_sellers_own_threshold(): void
    {
        $this->sellerProfile()->update(['free_shipping_threshold' => 100]);
        $this->addNailsToCart();

        Livewire::test(CartComponent::class)
            ->assertSee('Free delivery')
            ->assertDontSee('more for Free Shipping')
            ->assertSee('৳'.number_format(app(CartSessionService::class)->getSubtotal(), 2));
    }

    public function test_checkout_zone_options_show_the_fee_actually_charged_and_offer_sub_dhaka(): void
    {
        $this->sellerProfile()->update(['inside_dhaka_rate' => 90]);
        $this->addNailsToCart();

        $checkout = Livewire::test(CheckoutComponent::class)
            ->assertSee('৳90')
            ->assertSee('Sub-Dhaka')
            ->set('deliveryZone', 'sub_dhaka');

        $this->assertEqualsWithDelta(220.0, $checkout->instance()->getShippingFee(), 0.001);
    }

    // 2. Flash sale countdown
    public function test_flash_sale_countdown_counts_to_the_real_end_time(): void
    {
        $endsAt = now()->addHours(3)->startOfMinute();
        $this->nails->update(['is_flash_sale' => true, 'flash_sale_price' => 200, 'flash_sale_end' => $endsAt]);

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('Ends in:')
            ->assertSee('data-ends-at="'.$endsAt->getTimestampMs().'"', false)
            ->assertDontSee('hours: 14');
    }

    public function test_cart_charges_the_flash_sale_price_the_shop_shows(): void
    {
        $this->nails->update(['is_flash_sale' => true, 'flash_sale_price' => 199, 'flash_sale_end' => now()->addHours(3)]);

        $this->addNailsToCart();
        $cart = app(CartSessionService::class);
        $key = array_key_first($cart->getItems());
        $this->assertEquals(199.0, $cart->getItems()[$key]['unit_price']);

        $cart->updateQuantity($key, 2);
        $this->assertEquals(199.0, $cart->getItems()[$key]['unit_price']);

        // After the sale ends the normal price applies again
        $this->travel(4)->hours();
        $cart->updateQuantity($key, 3);
        $this->assertGreaterThan(199.0, $cart->getItems()[$key]['unit_price']);
    }

    public function test_flash_sale_without_end_time_shows_no_countdown(): void
    {
        $this->nails->update(['is_flash_sale' => true, 'flash_sale_price' => 200, 'flash_sale_end' => null]);

        $this->get(route('catalog.index'))->assertOk()->assertSee('Flash Sale')->assertDontSee('Ends in:');
    }

    // 3. Product claims
    public function test_product_page_shows_no_emi_offer_and_the_real_warranty(): void
    {
        $this->nails->update(['warranty_period' => '7 Days Replacement Guarantee']);

        $this->get(route('catalog.show', $this->nails->slug))
            ->assertOk()
            ->assertDontSee('0% Interest EMI')
            ->assertDontSee('Official Warranty')
            ->assertSee('7 Days Replacement Guarantee');
    }

    public function test_product_without_warranty_or_delivery_note_makes_no_invented_promise(): void
    {
        $this->nails->update(['warranty_period' => null, 'delivery_info' => null]);

        $this->get(route('catalog.show', $this->nails->slug))
            ->assertOk()
            ->assertDontSee('Official Manufacturer Warranty')
            ->assertDontSee('Express 24h delivery inside Dhaka');
    }

    // 4. Payment options
    public function test_checkout_and_footer_offer_only_payment_methods_that_work(): void
    {
        $this->addNailsToCart();

        Livewire::test(CheckoutComponent::class)->assertDontSee('SSLCommerz')->assertSee('Cash on Delivery');
        $this->get(route('catalog.index'))->assertDontSee('Visa')->assertDontSee('Cards & COD');

        $this->app->detectEnvironment(fn () => 'production');
        Livewire::test(CheckoutComponent::class)->assertDontSee('Mock Sandbox');
    }

    // 5. Listings
    public function test_products_without_a_shop_price_or_from_unapproved_sellers_are_not_listed(): void
    {
        $noPrice = $this->nails->replicate(['slug', 'sku']);
        $noPrice->fill(['slug' => 'no-price-item', 'sku' => 'NOPRICE-1', 'title' => 'Priceless Mystery Item', 'is_featured' => true]);
        $noPrice->save();
        ProductPrice::create([
            'product_id' => $noPrice->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id'),
            'unit_price' => 100,
            'min_quantity' => 10,
        ]);

        $otherSeller = User::where('email', 'second@factory.test')->first() ?? User::factory()->create();
        $hidden = $this->nails->replicate(['slug', 'sku']);
        $hidden->fill(['slug' => 'unapproved-item', 'sku' => 'UNAPP-1', 'title' => 'Unapproved Seller Gadget', 'seller_id' => $otherSeller->id]);
        $hidden->save();
        SellerProfile::where('user_id', $otherSeller->id)->update(['is_approved' => false]);

        $this->get(route('catalog.index'))->assertOk()
            ->assertDontSee('Priceless Mystery Item')
            ->assertDontSee('Unapproved Seller Gadget')
            ->assertSee($this->nails->title);

        $this->get(route('catalog.show', $this->nails->slug))->assertOk()
            ->assertDontSee('Priceless Mystery Item')
            ->assertDontSee('Customers who bought this product also frequently bundled');
    }

    public function test_cart_refuses_a_zero_price(): void
    {
        $this->nails->prices()->update(['unit_price' => 0]);

        $this->expectException(Exception::class);
        $this->addNailsToCart();
    }

    public function test_seller_must_give_a_shop_price_for_a_shop_product(): void
    {
        $seller = User::findOrFail($this->nails->seller_id);
        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $undo = Repeater::fake();

        $wholesale = SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id');
        $both = SalesChannel::where('slug', SalesChannel::BOTH)->value('id');

        Livewire::test(EditProduct::class, ['record' => $this->nails->getRouteKey()])
            ->fillForm([
                'sales_channel_id' => $both,
                'prices' => [['sales_channel_id' => $wholesale, 'unit_price' => 100, 'min_quantity' => 10, 'max_quantity' => null]],
            ])
            ->call('save')
            ->assertHasFormErrors(['prices']);

        $undo();
    }

    // 6. Policy pages
    public function test_policy_pages_exist_and_are_linked(): void
    {
        foreach (['pages.terms', 'pages.privacy', 'pages.returns', 'pages.about', 'pages.contact'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('B2B Haat');
        }

        $this->get(route('pages.returns'))->assertSee('48 hours');

        $this->get(route('catalog.index'))
            ->assertSee(route('pages.terms'), false)
            ->assertSee(route('pages.privacy'), false)
            ->assertSee(route('pages.returns'), false);

        $this->get(route('register'))
            ->assertSee(route('pages.terms'), false)
            ->assertSee(route('pages.privacy'), false);
    }

    // 7. Return window = seller payment hold
    public function test_return_must_be_requested_within_the_payment_hold_window(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        $sub = $order->subOrders->firstWhere('subtotal', 500);
        $delivered = FulfillmentStatus::where('slug', 'delivered')->value('id');
        $sub->update(['fulfillment_status_id' => $delivered]);
        OrderTrackingLog::create([
            'sub_order_id' => $sub->id, 'fulfillment_status_id' => $delivered,
            'title' => 'Delivered', 'description' => 'Delivered', 'logged_at' => now(),
        ]);

        $this->travel(49)->hours();

        $this->expectExceptionMessage('48 hours');
        app(OrderDisputeService::class)->submitReturnRequest(SubOrder::findOrFail($sub->id), User::findOrFail($order->customer_id), [
            'reason' => 'damaged_goods', 'requested_action' => 'refund', 'description' => 'Late',
        ]);
    }

    // 8. Store details
    public function test_store_details_never_show_invented_values(): void
    {
        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertDontSee('B2Bhaat Tower')
            ->assertDontSee('BIN-002394812')
            ->assertDontSee('9612-445566');
    }

    // 9. Footer and panels
    public function test_footer_hides_admin_portal_and_tech_stack(): void
    {
        $this->get(route('catalog.index'))
            ->assertDontSee('Super Admin')
            ->assertDontSee('Built with Laravel');
    }

    public function test_panels_carry_the_store_name(): void
    {
        $this->get('/seller/login')->assertOk()->assertSee('B2B Haat')->assertDontSee('<title>Login - Laravel', false);
    }
}
