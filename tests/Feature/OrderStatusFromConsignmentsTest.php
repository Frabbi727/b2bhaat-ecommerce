<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * ORD-1: an order's status follows its parcels, and a full cancel never cancels an order that is partly on its way.
 */
class OrderStatusFromConsignmentsTest extends TestCase
{
    use RefreshDatabase;

    private OrderDisputeService $disputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        Queue::fake();
        $this->disputes = app(OrderDisputeService::class);
    }

    /**
     * @return array{0: SubOrder, 1: SubOrder}
     */
    private function parcels(Order $order, string $firstSlug, string $secondSlug): array
    {
        $order->load('subOrders');
        $first = $order->subOrders->firstWhere('subtotal', 1000);
        $second = $order->subOrders->firstWhere('subtotal', 500);
        $first->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $firstSlug)->value('id')]);
        $second->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $secondSlug)->value('id')]);

        return [$first->fresh(), $second->fresh()];
    }

    private function orderStatus(Order $order): ?string
    {
        return $order->fresh()->orderStatus?->slug;
    }

    private function fulfilment(SubOrder $subOrder): ?string
    {
        return $subOrder->fresh()->fulfillmentStatus?->slug;
    }

    public function test_full_cancel_of_a_partly_shipped_order_cancels_only_the_waiting_parcel(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$waiting, $shipped] = $this->parcels($order, 'unfulfilled', 'shipped');
        $statusBefore = $this->orderStatus($order);

        $result = $this->disputes->cancelFullOrder($order->fresh(), 'Changed mind');

        $this->assertSame(['cancelled' => 1, 'kept' => 1], $result);
        $this->assertSame('cancelled', $this->fulfilment($waiting));
        $this->assertSame('shipped', $this->fulfilment($shipped));
        $this->assertNotSame('cancelled', $this->orderStatus($order));
        $this->assertSame($statusBefore, $this->orderStatus($order));
    }

    public function test_full_cancel_is_refused_when_every_parcel_has_shipped(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        $this->parcels($order, 'shipped', 'in_transit');
        $statusBefore = $this->orderStatus($order);

        try {
            $this->disputes->cancelFullOrder($order->fresh(), 'Too late');
            $this->fail('A full cancel of an order already on its way must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('already on its way', $e->getMessage());
        }

        $this->assertSame($statusBefore, $this->orderStatus($order));
    }

    public function test_one_parcel_cancelled_and_the_other_delivered_completes_the_order(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$cancelled, $courierParcel] = $this->parcels($order, 'unfulfilled', 'shipped');

        $this->disputes->cancelSubOrder($cancelled, 'Out of stock');
        $this->assertNotSame('cancelled', $this->orderStatus($order));

        app(CourierStatusService::class)->updateStatus($courierParcel, 'delivered');

        $this->assertSame('completed', $this->orderStatus($order));
    }

    public function test_one_parcel_delivered_and_the_other_returned_completes_the_order(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$returning, $courierParcel] = $this->parcels($order, 'shipped', 'shipped');

        app(CourierStatusService::class)->updateStatus($courierParcel, 'delivered');
        $this->assertNotSame('completed', $this->orderStatus($order));

        app(CourierStatusService::class)->updateStatus($returning, 'returned');

        $this->assertSame('completed', $this->orderStatus($order));
    }

    public function test_cancelling_every_parcel_of_a_paid_order_cancels_and_refunds_it(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        [$first, $second] = $this->parcels($order, 'unfulfilled', 'processing');

        $this->disputes->cancelSubOrder($first, 'Changed mind');
        $this->assertNotSame('cancelled', $this->orderStatus($order));

        $this->disputes->cancelSubOrder($second, 'Changed mind');

        $this->assertSame('cancelled', $this->orderStatus($order));
        $this->assertSame('refunded', $order->fresh()->paymentStatus?->slug);
    }

    public function test_a_wrongly_closed_order_reopens_when_a_parcel_is_still_on_its_way(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$delivered, $open] = $this->parcels($order, 'shipped', 'shipped');
        // Left behind by the old full cancel: the order says cancelled although parcels were shipped.
        $order->update(['order_status_id' => OrderStatus::where('slug', 'cancelled')->value('id')]);

        app(CourierStatusService::class)->updateStatus($delivered, 'delivered');

        $this->assertSame('processing', $this->orderStatus($order));
        $this->assertSame('shipped', $this->fulfilment($open));
    }

    public function test_admin_cannot_set_the_order_status_by_hand(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $order = MixedOrderFixture::create('cod', 'unpaid');

        $this->assertFalse(OrderResource::hasPage('edit'));

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('edit');
    }
}
