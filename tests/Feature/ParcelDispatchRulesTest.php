<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Livewire\Storefront\CheckoutComponent;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\CourierBookingService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Logistics\SelfDeliveryService;
use App\Services\Orders\OrderDisputeService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ORD-2: handing a parcel to a courier, booking a pickup and starting a self-delivery follow the same status rules as
 * courier updates, under the same lock, and never for an order whose payment isn't cleared.
 */
class ParcelDispatchRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        app(StoreConfigurationService::class)->set('bkash_merchant_number', '01811-223344', 'payments');
    }

    private function parcel(string $method): SubOrder
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 1);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Dispatch Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', $method)->value('id'))
            ->set('mfsSenderNumber', '01899001188')
            ->set('mfsTransactionId', 'TRXDISP'.random_int(100, 999))
            ->call('placeOrder');

        return Order::where('customer_id', $customer->id)->latest('id')->firstOrFail()->subOrders()->firstOrFail();
    }

    private function cancelled(): SubOrder
    {
        $parcel = $this->parcel('cod');
        app(OrderDisputeService::class)->cancelSubOrder($parcel, 'Changed my mind');

        return $parcel->fresh();
    }

    public function test_a_cancelled_parcel_cannot_be_handed_to_a_courier(): void
    {
        $parcel = $this->cancelled();

        try {
            app(CourierStatusService::class)->handOver($parcel, 'shipped', ['courier_name' => 'Pathao Courier', 'tracking_number' => 'PT-1'], 'Handed Over to Courier', 'note', 'Vendor Warehouse');
            $this->fail('A cancelled parcel must not be dispatched.');
        } catch (InvalidStatusTransitionException) {
        }

        $this->assertSame('cancelled', $parcel->fresh()->fulfillmentStatus->slug);
        $this->assertNull($parcel->fresh()->tracking_number);
    }

    public function test_a_cancelled_parcel_cannot_start_a_self_delivery_or_a_booking(): void
    {
        $parcel = $this->cancelled();

        foreach ([
            fn () => app(SelfDeliveryService::class)->dispatchSelfDelivery($parcel),
            fn () => app(CourierBookingService::class)->schedulePickup($parcel, ['courier_name' => 'Paperfly', 'pickup_slot' => 'Today', 'pickup_address' => 'Hub']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A cancelled parcel must not be dispatched.');
            } catch (InvalidStatusTransitionException|DomainException) {
            }
        }

        $parcel->refresh();
        $this->assertSame('cancelled', $parcel->fulfillmentStatus->slug);
        $this->assertFalse((bool) $parcel->is_self_delivered);
        $this->assertNull($parcel->handover_code);
    }

    public function test_an_unverified_payment_blocks_dispatch_even_without_the_panel(): void
    {
        $parcel = $this->parcel('bkash');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('payment');

        app(SelfDeliveryService::class)->dispatchSelfDelivery($parcel);
    }

    public function test_a_cod_parcel_is_handed_over_with_a_tracking_log(): void
    {
        $parcel = $this->parcel('cod');

        app(CourierStatusService::class)->handOver($parcel, 'shipped', ['courier_name' => 'Pathao Courier', 'tracking_number' => 'PT-2'], 'Handed Over to Courier', 'At the hub', 'Vendor Warehouse');

        $parcel->refresh();
        $this->assertSame('shipped', $parcel->fulfillmentStatus->slug);
        $this->assertSame('PT-2', $parcel->tracking_number);
        $this->assertSame('Handed Over to Courier', $parcel->trackingLogs()->latest('id')->value('title'));
    }
}
