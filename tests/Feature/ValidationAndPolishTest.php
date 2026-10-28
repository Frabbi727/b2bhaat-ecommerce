<?php

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\OrderTracking;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ValidationAndPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_checkout_validates_bangladeshi_phone_number_format(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $this->actingAs($retailUser);

        $cart = app(CartSessionService::class);
        $cart->addItem($product->id, $variation->id, 'retail', 1);

        // Submitting with invalid phone should fail validation
        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Tanvir')
            ->set('recipientPhone', 'invalid-phone-string')
            ->set('streetAddress', 'House 14, Road 5')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1205')
            ->call('placeOrder')
            ->assertHasErrors(['recipientPhone']);
    }

    public function test_product_detail_validates_excess_stock_requests(): void
    {
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $variation = $product->variations()->firstOrFail();

        $this->actingAs($retailUser);

        // Requesting 99999 units when stock is much lower
        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('selectedVariationId', $variation->id)
            ->set('retailQuantity', 999999)
            ->call('addToCartRetail')
            ->assertSee('exceeds available stock');
    }

    public function test_order_tracking_finds_order_by_courier_tracking_number(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $order = Order::create([
            'order_number' => 'ORD-TRACK-TEST-001',
            'customer_id' => $customer->id,
            'order_status_id' => 1,
            'payment_status_id' => 1,
            'payment_method_id' => 1,
            'total_amount' => 500,
            'shipping_amount' => 70,
            'shipping_address' => ['recipient_phone' => '+8801700000004'],
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => 'PKG-SUB-9988',
            'fulfillment_status_id' => 1,
            'courier_name' => 'Steadfast',
            'tracking_number' => 'STDFST-CONSIGNMENT-777',
            'subtotal' => 500,
            'commission_amount' => 50,
        ]);

        // Search by Courier Tracking Number directly
        Livewire::test(OrderTracking::class)
            ->set('orderNumber', 'STDFST-CONSIGNMENT-777')
            ->call('searchOrder')
            ->assertSet('searchError', '')
            ->assertSee('ORD-TRACK-TEST-001')
            ->assertSee('STDFST-CONSIGNMENT-777');
    }
}
