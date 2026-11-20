<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CheckoutComponent;
use App\Models\FlashSaleProduct;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FS-1 / INV-7: flash-sale units a sale claimed go back to the offer whenever that sale is undone, exactly once.
 */
class FlashUnitReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    /**
     * A COD parcel of 2 units whose line claimed units of a flash offer that has sold 5.
     *
     * @return array{0: SubOrder, 1: FlashSaleProduct}
     */
    private function flashParcel(): array
    {
        $this->actingAs($this->customer);
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $cart = app(CartSessionService::class);
        $cart->clear();
        $cart->addItem(productId: $product->id, variationId: $product->variations()->firstOrFail()->id, channelSlug: SalesChannel::RETAIL, quantity: 2);

        Livewire::test(CheckoutComponent::class)
            ->set('recipientName', 'Flash Buyer')
            ->set('recipientPhone', '01899001188')
            ->set('streetAddress', 'Road 3, CDA Avenue')
            ->set('city', 'Dhaka')
            ->set('postalCode', '1200')
            ->set('deliveryZone', 'inside_dhaka')
            ->set('selectedPaymentMethodId', PaymentMethod::where('slug', 'cod')->value('id'))
            ->call('placeOrder');

        $parcel = Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail()->subOrders()->firstOrFail();
        $offer = FlashSaleProduct::factory()->create(['product_id' => $product->id, 'units_sold' => 5]);
        $parcel->items()->update(['flash_sale_product_id' => $offer->id]);

        return [$parcel->fresh(), $offer];
    }

    public function test_an_expired_unpaid_reservation_returns_its_units_once(): void
    {
        [$parcel, $offer] = $this->flashParcel();
        Order::whereKey($parcel->parent_order_id)->update([
            'order_status_id' => OrderStatus::where('slug', 'pending')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->value('id'),
            'created_at' => now()->subHour(),
        ]);

        $this->artisan('inventory:release-expired-reservations', ['--minutes' => 15])->assertSuccessful();
        $this->artisan('inventory:release-expired-reservations', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame(3, (int) $offer->fresh()->units_sold);
        $this->assertSame('cancelled', $parcel->fresh()->fulfillmentStatus->slug);
    }

    public function test_a_parcel_returned_by_the_courier_returns_its_units(): void
    {
        [$parcel, $offer] = $this->flashParcel();
        $courier = app(CourierStatusService::class);
        $courier->handOver($parcel, 'shipped', ['courier_name' => 'Pathao Courier', 'tracking_number' => 'PT-RTO'], 'Handed Over', 'note', 'Hub');

        $courier->updateStatus($parcel, 'returned');

        $this->assertSame(3, (int) $offer->fresh()->units_sold);
    }

    public function test_an_approved_return_returns_its_units(): void
    {
        [$parcel, $offer] = $this->flashParcel();
        $courier = app(CourierStatusService::class);
        $courier->handOver($parcel, 'shipped', ['courier_name' => 'Pathao Courier', 'tracking_number' => 'PT-RET'], 'Handed Over', 'note', 'Hub');
        $courier->updateStatus($parcel, 'delivered');

        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($parcel->fresh(), $this->customer, ['reason' => 'Damaged', 'requested_action' => 'refund', 'description' => 'Broken tips']);
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'OK');

        $this->assertSame(3, (int) $offer->fresh()->units_sold);
    }
}
