<?php

namespace Tests\Feature;

use App\Filament\Seller\Pages\StoreSettings;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Orders\OrderSplitterService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerConfigurableShippingRatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected User $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->product = Product::where('seller_id', $this->seller->id)->firstOrFail();
    }

    public function test_seller_can_update_custom_shipping_rates_and_threshold_in_store_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);

        Livewire::test(StoreSettings::class)
            ->set('inside_dhaka_rate', 55.00)
            ->set('outside_dhaka_rate', 140.00)
            ->set('free_shipping_threshold', 3000.00)
            ->call('save')
            ->assertHasNoErrors();

        $profile = $this->seller->sellerProfile->fresh();

        $this->assertEquals(55.00, (float) $profile->inside_dhaka_rate);
        $this->assertEquals(140.00, (float) $profile->outside_dhaka_rate);
        $this->assertEquals(3000.00, (float) $profile->free_shipping_threshold);
    }

    public function test_shipping_calculator_uses_seller_custom_rates_below_threshold(): void
    {
        $profile = $this->seller->sellerProfile;
        $profile->update([
            'inside_dhaka_rate' => 60.00,
            'outside_dhaka_rate' => 150.00,
            'free_shipping_threshold' => 5000.00,
        ]);

        $items = [
            [
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 300.00, // Total ৳600 (< ৳5000 threshold)
            ],
        ];

        $service = app(ShippingCalculatorService::class);

        // Inside Dhaka
        $insideCalc = $service->calculateShipping($items, 'inside_dhaka');
        $this->assertEquals(60.00, $insideCalc['total_shipping']);
        $this->assertFalse($insideCalc['vendors'][$this->seller->id]['is_free']);

        // Outside Dhaka
        $outsideCalc = $service->calculateShipping($items, 'outside_dhaka');
        $this->assertEquals(150.00, $outsideCalc['total_shipping']);
        $this->assertFalse($outsideCalc['vendors'][$this->seller->id]['is_free']);
    }

    public function test_shipping_calculator_awards_free_shipping_when_vendor_subtotal_meets_threshold(): void
    {
        $profile = $this->seller->sellerProfile;
        $profile->update([
            'inside_dhaka_rate' => 60.00,
            'outside_dhaka_rate' => 150.00,
            'free_shipping_threshold' => 2000.00,
        ]);

        $items = [
            [
                'product_id' => $this->product->id,
                'quantity' => 10,
                'unit_price' => 250.00, // Total ৳2500 (>= ৳2000 threshold)
            ],
        ];

        $service = app(ShippingCalculatorService::class);
        $calc = $service->calculateShipping($items, 'outside_dhaka');

        $this->assertEquals(0.00, $calc['total_shipping']);
        $this->assertTrue($calc['vendors'][$this->seller->id]['is_free']);
    }

    public function test_shipping_calculator_falls_back_to_platform_defaults_when_unset(): void
    {
        $profile = $this->seller->sellerProfile;
        $profile->update([
            'inside_dhaka_rate' => null,
            'outside_dhaka_rate' => null,
            'free_shipping_threshold' => null,
        ]);

        $items = [
            [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 500.00,
            ],
        ];

        $service = app(ShippingCalculatorService::class);

        $insideCalc = $service->calculateShipping($items, 'inside_dhaka');
        $this->assertEquals(70.00, $insideCalc['total_shipping']);

        $outsideCalc = $service->calculateShipping($items, 'outside_dhaka');
        $this->assertEquals(130.00, $outsideCalc['total_shipping']);
    }

    public function test_checkout_component_dynamically_calculates_and_displays_shipping_breakdown(): void
    {
        $profile = $this->seller->sellerProfile;
        $profile->update([
            'inside_dhaka_rate' => 65.00,
            'outside_dhaka_rate' => 135.00,
            'free_shipping_threshold' => 4000.00,
        ]);

        $cart = app(CartSessionService::class);
        $cart->clear();

        $variation = $this->product->variations->first();
        $cart->addItem($this->product->id, $variation?->id, SalesChannel::RETAIL, 2);

        $this->actingAs($this->customer);

        Livewire::test(CheckoutComponent::class)
            ->set('deliveryZone', 'inside_dhaka')
            ->assertSee('Courier & Delivery Freight:', false)
            ->assertSee('৳65.00')
            ->set('deliveryZone', 'outside_dhaka')
            ->assertSee('৳135.00');
    }

    public function test_order_splitter_records_allocated_vendor_shipping_on_sub_order(): void
    {
        $profile = $this->seller->sellerProfile;
        $profile->update([
            'inside_dhaka_rate' => 85.00,
            'outside_dhaka_rate' => 160.00,
            'free_shipping_threshold' => 10000.00,
        ]);

        $paymentMethod = PaymentMethod::firstOrFail();
        $variation = $this->product->variations->first();
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();

        $splitter = app(OrderSplitterService::class);
        $order = $splitter->createAndSplitOrder($this->customer, [
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => [
                'recipient_name' => 'Tanvir Hasan',
                'recipient_phone' => '+8801700000004',
                'street_address' => 'Mirpur Road 10',
                'city' => 'Dhaka',
                'postal_code' => '1216',
                'delivery_zone' => 'inside_dhaka',
            ],
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variation_id' => $variation?->id,
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 1,
                    'unit_price' => 450.00,
                ],
            ],
        ]);

        $this->assertEquals(85.00, (float) $order->shipping_amount);
        $subOrder = $order->subOrders->first();
        $this->assertNotNull($subOrder);
        $this->assertEquals(85.00, (float) $subOrder->shipping_amount);
    }
}
