<?php

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_catalog_page_renders_successfully(): void
    {
        Livewire::test(ProductCatalog::class)
            ->assertStatus(200)
            ->assertSee('Luxury Press-on Fake Nails Set')
            ->assertSee('Consumer Market Mode');
    }

    public function test_retail_user_can_add_single_unit_to_cart(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('selectedVariationId', $variation->id)
            ->set('retailQuantity', 2)
            ->call('addToCartRetail')
            ->assertSet('feedbackMessage', 'Added 2 unit(s) to your cart.');

        $cart = app(CartSessionService::class);
        $this->assertEquals(2, $cart->getItemCount());
        $this->assertEquals(500.00, $cart->getSubtotal());
    }

    public function test_wholesale_user_sees_matrix_and_enforces_moq(): void
    {
        $wholesaleUser = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($wholesaleUser);

        $variation1 = $product->variations()->firstOrFail();

        // 1. Trying to order below MOQ (e.g. 10 < 50) fails
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variation1->id}", 10)
            ->call('addBulkMatrixToCart')
            ->assertSee('Wholesale bulk order requires at least 50 units.');

        // 2. Ordering >= MOQ (50 units) succeeds
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set("matrixQuantities.{$variation1->id}", 50)
            ->call('addBulkMatrixToCart')
            ->assertSee('Successfully added wholesale bulk lot (50 units)');

        $cart = app(CartSessionService::class);
        $this->assertEquals(50, $cart->getItemCount());
        $this->assertEquals(5500.00, $cart->getSubtotal()); // 50 * 110.00 = 5500.00
    }

    public function test_end_to_end_checkout_splits_sub_orders_and_holds_escrow(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $mockPayment = PaymentMethod::where('slug', 'mock_gateway')->firstOrFail();

        $this->actingAs($retailUser);

        // Put item in cart
        $cart = app(CartSessionService::class);
        $cart->addItem($product->id, $variation->id, 'retail', 2);

        $sellerWallet = SellerWallet::where('seller_id', $product->seller_id)->firstOrFail();
        $initialHeld = (float) $sellerWallet->escrow_held_balance;

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir Hasan')
            ->set('recipientPhone', '+8801700000004')
            ->set('streetAddress', 'House 14, Road 5, Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1205')
            ->set('selectedPaymentMethodId', $mockPayment->id)
            ->call('placeOrder')
            ->assertHasNoErrors();

        // Check master order
        $order = Order::where('customer_id', $retailUser->id)->latest()->firstOrFail();
        $this->assertEquals(570.00, (float) $order->total_amount); // 500 items + 70 inside Dhaka delivery
        $this->assertEquals(70.00, (float) $order->shipping_amount);
        $this->assertEquals('paid', $order->paymentStatus->slug);

        // Check sub-order
        $this->assertCount(1, $order->subOrders);
        $subOrder = $order->subOrders->first();
        $this->assertEquals(500.00, (float) $subOrder->subtotal);
        $this->assertEquals(50.00, (float) $subOrder->commission_amount); // 10%
        $this->assertEquals(450.00, $subOrder->net_earnings);

        // Check seller wallet escrow hold
        $sellerWallet->refresh();
        $this->assertEquals($initialHeld + 450.00, (float) $sellerWallet->escrow_held_balance);
    }
}
