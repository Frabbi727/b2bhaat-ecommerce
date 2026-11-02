<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicStoreConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_store_setting_persists_type_and_returns_casted_value(): void
    {
        StoreSetting::setTypedValue('free_shipping_threshold', 10000.00, 'delivery', 'float');
        $this->assertDatabaseHas('store_settings', [
            'key' => 'free_shipping_threshold',
            'type' => 'float',
            'group' => 'delivery',
        ]);

        $val = StoreSetting::getTypedValue('free_shipping_threshold');
        $this->assertSame(10000.00, $val);
    }

    public function test_store_configuration_service_returns_typed_domain_defaults(): void
    {
        $service = app(StoreConfigurationService::class);
        $this->assertEquals(70.00, $service->getDeliveryFee('inside_dhaka'));
        $this->assertEquals(100.00, $service->getDeliveryFee('sub_dhaka'));
        $this->assertEquals(130.00, $service->getDeliveryFee('outside_dhaka'));
        $this->assertEquals(10000.00, $service->getFreeShippingThreshold());
        $this->assertEquals(10, $service->getLowStockThreshold());
        $this->assertEquals(15, $service->getStockReservationTtl());
        $this->assertTrue($service->isGuestCheckoutAllowed());
        $this->assertEquals(24, $service->getOrderCancellationWindowHours());
        $this->assertEquals(50000.00, $service->getCodMaxLimit());
    }

    public function test_super_admin_can_update_settings_and_sellers_are_forbidden(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Admin can access
        $this->actingAs($admin);
        Livewire::test(StoreSettings::class)
            ->assertSuccessful()
            ->set('data.delivery_fee_inside_dhaka', 85.00)
            ->set('data.free_shipping_threshold', 12000.00)
            ->call('save')
            ->assertHasNoErrors();

        $service = app(StoreConfigurationService::class);
        $this->assertEquals(85.00, $service->getDeliveryFee('inside_dhaka'));
        $this->assertEquals(12000.00, $service->getFreeShippingThreshold());

        // Seller is forbidden
        $this->actingAs($seller);
        $this->get('/admin/store-settings')->assertForbidden();
    }

    public function test_shipping_calculator_uses_dynamic_freight_rates_and_threshold(): void
    {
        $config = app(StoreConfigurationService::class);
        $config->set('delivery_fee_inside_dhaka', 90.00, 'delivery', 'float');
        $config->set('free_shipping_threshold', 15000.00, 'delivery', 'float');

        $calc = app(ShippingCalculatorService::class);
        $product = Product::firstOrFail();

        // Clear seller custom overrides if any so platform defaults apply
        if ($product->seller?->sellerProfile) {
            $product->seller->sellerProfile->update([
                'inside_dhaka_rate' => null,
                'outside_dhaka_rate' => null,
                'free_shipping_threshold' => null,
            ]);
        }

        $items = [[
            'product_id' => $product->id,
            'variation_id' => null,
            'quantity' => 1,
            'unit_price' => 2000.00,
        ]];

        $res = $calc->calculateShipping($items, 'inside_dhaka');
        $this->assertEquals(90.00, (float) $res['total_shipping']);
    }

    public function test_checkout_enforces_cod_ceiling_and_dispute_respects_cancellation_window(): void
    {
        $config = app(StoreConfigurationService::class);
        $config->set('cod_max_order_limit', 5000.00, 'checkout', 'float');
        $config->set('cancellation_window_hours', 12, 'checkout', 'integer');

        $buyer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($buyer);

        // Place an order exceeding 5000 Tk and verify COD is restricted
        $cart = app(CartSessionService::class);
        $product = Product::where('slug', 'haier-55-inch-mini-led-4k-uhd-google-tv-h55m80gux')->firstOrFail();
        $cart->addItem($product->id, null, SalesChannel::RETAIL, 1);

        $codMethod = PaymentMethod::where('slug', 'cod')->firstOrFail();

        Livewire::test(CheckoutComponent::class)
            ->set('selectedPaymentMethodId', $codMethod->id)
            ->call('placeOrder')
            ->assertSee('Cash on Delivery is only available for orders up to ৳5,000');

        // Verify dispute cancellation window is respected
        $order = Order::firstOrFail();
        $subOrder = $order->subOrders()->firstOrFail();
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();
        $subOrder->fulfillment_status_id = $unfulfilledStatus->id;
        $subOrder->created_at = now()->subHours(15);
        $subOrder->save();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cancellation window of 12 hours has elapsed');
        app(OrderDisputeService::class)->cancelSubOrder($subOrder);
    }
}
