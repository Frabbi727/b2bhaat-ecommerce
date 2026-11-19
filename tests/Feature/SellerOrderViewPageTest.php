<?php

namespace Tests\Feature;

use App\Filament\Seller\Resources\SubOrders\Pages\ViewSubOrder;
use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Formatting\Money;
use App\Services\Logistics\SelfDeliveryService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerOrderViewPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SubOrder $subOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->subOrder = SubOrder::where('seller_id', $this->seller->id)->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
    }

    public function test_a_new_order_shows_what_to_pack_who_it_goes_to_and_the_payout(): void
    {
        $this->subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $subOrder = $this->subOrder->fresh(['items.product', 'order']);

        Livewire::test(ViewSubOrder::class, ['record' => $subOrder->getRouteKey()])
            ->assertOk()
            ->assertSee('Order '.$subOrder->sub_order_number)
            ->assertSee('Items to pack')
            ->assertSee($subOrder->items->first()->product->title)
            ->assertSee('Deliver to')
            ->assertSee($subOrder->order->shipping_address['recipient_name'])
            ->assertSee('You receive')
            ->assertSee(Money::taka($subOrder->sellerPayableAmount()))
            ->assertSee('Delivery progress');
    }

    public function test_a_self_delivered_parcel_tells_the_seller_to_take_the_customers_code(): void
    {
        app(SelfDeliveryService::class)->dispatchSelfDelivery($this->subOrder);

        Livewire::test(ViewSubOrder::class, ['record' => $this->subOrder->getRouteKey()])
            ->assertOk()
            ->assertSee('On the way with your delivery person')
            ->assertSee('Confirm Handover Code')
            ->assertDontSee($this->subOrder->fresh()->handover_code);
    }

    public function test_a_cancelled_order_shows_the_reason_and_no_payout(): void
    {
        $this->subOrder->update([
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'cancelled')->value('id'),
            'cancelled_by' => 'buyer',
            'cancel_reason' => 'Ordered by mistake',
            'cancelled_at' => now(),
        ]);

        Livewire::test(ViewSubOrder::class, ['record' => $this->subOrder->getRouteKey()])
            ->assertOk()
            ->assertSee('This order was cancelled')
            ->assertSee('Ordered by mistake')
            ->assertSee('Cancelled orders are not paid out.')
            ->assertDontSee('Delivery progress');
    }
}
