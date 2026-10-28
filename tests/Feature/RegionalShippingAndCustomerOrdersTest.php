<?php

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\CustomerOrders;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegionalShippingAndCustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_shipping_fee_is_70_tk_inside_dhaka(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $mockPayment = PaymentMethod::where('slug', 'mock_gateway')->firstOrFail();

        $this->actingAs($user);

        // Put 1 item in cart (৳250)
        $cart = app(CartSessionService::class);
        $cart->addItem($product->id, $variation->id, 'retail', 1);

        Livewire::test(CheckoutComponent::class)
            ->set('deliveryZone', 'inside_dhaka')
            ->set('recipientName', 'Tanvir Hasan')
            ->set('recipientPhone', '+8801700000004')
            ->set('streetAddress', 'Dhanmondi')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1205')
            ->set('selectedPaymentMethodId', $mockPayment->id)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEquals(70.00, (float) $order->shipping_amount);
        $this->assertEquals(320.00, (float) $order->total_amount); // 250 + 70 = 320
    }

    public function test_shipping_fee_is_130_tk_outside_dhaka(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $mockPayment = PaymentMethod::where('slug', 'mock_gateway')->firstOrFail();

        $this->actingAs($user);

        // Put 1 item in cart (৳250)
        $cart = app(CartSessionService::class);
        $cart->addItem($product->id, $variation->id, 'retail', 1);

        Livewire::test(CheckoutComponent::class)
            ->set('deliveryZone', 'outside_dhaka')
            ->set('recipientName', 'Chittagong Buyer')
            ->set('recipientPhone', '+8801700000004')
            ->set('streetAddress', 'Agrabad')
            ->set('city', 'Chittagong')
            ->set('postalCode', '4000')
            ->set('selectedPaymentMethodId', $mockPayment->id)
            ->call('placeOrder')
            ->assertHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEquals(130.00, (float) $order->shipping_amount);
        $this->assertEquals(380.00, (float) $order->total_amount); // 250 + 130 = 380
    }

    public function test_customer_can_view_my_orders_dashboard(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($user);

        Livewire::test(CustomerOrders::class)
            ->assertStatus(200)
            ->assertSee('My Order History');
    }
}
