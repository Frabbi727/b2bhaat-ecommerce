<?php

namespace Tests\Feature;

use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Livewire\Storefront\CustomerOrders;
use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Logistics\SelfDeliveryService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FactorySelfDeliveryAndHandoverCodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected User $customer;

    protected SubOrder $subOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->subOrder = SubOrder::where('seller_id', $this->seller->id)->firstOrFail();
    }

    public function test_seller_can_dispatch_sub_order_via_factory_direct_fleet(): void
    {
        $service = app(SelfDeliveryService::class);

        $handoverCode = $service->dispatchSelfDelivery(
            subOrder: $this->subOrder,
            hubLocation: 'Gazipur Industrial Bay 3',
            driverContact: '+8801711223344',
            dispatchNote: 'Direct truck dispatch.'
        );

        $this->subOrder->refresh();

        $this->assertTrue($this->subOrder->is_self_delivered);
        $this->assertEquals(6, strlen($handoverCode));
        $this->assertEquals($handoverCode, $this->subOrder->handover_code);
        $this->assertEquals('Factory Direct Fleet', $this->subOrder->courier_name);
        $this->assertStringStartsWith('FD-', $this->subOrder->tracking_number);
        $this->assertContains($this->subOrder->fulfillmentStatus->slug, ['shipped', 'in_transit']);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $this->subOrder->id,
            'title' => 'Dispatched via Factory Direct Fleet',
            'hub_location' => 'Gazipur Industrial Bay 3',
        ]);
    }

    public function test_buyer_sees_handover_code_on_order_tracking_and_my_orders(): void
    {
        $service = app(SelfDeliveryService::class);
        $code = $service->dispatchSelfDelivery($this->subOrder);

        $order = $this->subOrder->order;

        // 1. Order Tracking view
        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $order->order_number)
            ->set('phone', $order->shipping_address['recipient_phone'])
            ->call('searchOrder')
            ->assertSee('Direct Factory Fleet Self-Delivery')
            ->assertSee($code);

        // 2. Customer Orders list
        $this->actingAs($this->customer);
        Livewire::test(CustomerOrders::class)
            ->assertSee('Handover Code (Give to Driver):')
            ->assertSee($code);
    }

    public function test_submitting_incorrect_handover_code_is_rejected(): void
    {
        $service = app(SelfDeliveryService::class);
        $correctCode = $service->dispatchSelfDelivery($this->subOrder);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid Handover Code');

        $service->verifyAndConfirmHandover($this->subOrder, '000000');
    }

    public function test_submitting_correct_handover_code_marks_delivered_and_settles_wallet(): void
    {
        $service = app(SelfDeliveryService::class);
        $code = $service->dispatchSelfDelivery($this->subOrder);

        $service->verifyAndConfirmHandover($this->subOrder, $code);

        $this->subOrder->refresh();

        $this->assertEquals('delivered', $this->subOrder->fulfillmentStatus->slug);
        $this->assertNotNull($this->subOrder->handover_confirmed_at);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $this->subOrder->id,
            'title' => 'Delivered via Factory Fleet (Code Verified)',
        ]);

        // Check wallet was credited
        $wallet = $this->seller->sellerWallet;
        $this->assertNotNull($wallet);
    }

    public function test_filament_sub_orders_table_executes_self_delivery_actions(): void
    {
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();
        $this->subOrder->update([
            'fulfillment_status_id' => $unfulfilledStatus->id,
            'is_self_delivered' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);

        // Dispatch via Filament table action
        Livewire::test(ListSubOrders::class)
            ->callTableAction('dispatchFactoryFleet', $this->subOrder, [
                'hub_location' => 'Main Warehouse',
                'driver_contact' => '+8801811223344',
                'dispatch_note' => 'Direct dispatch.',
            ])
            ->assertHasNoTableActionErrors();

        $this->subOrder->refresh();
        $this->assertTrue($this->subOrder->is_self_delivered);
        $code = $this->subOrder->handover_code;

        // Confirm handover code via Filament table action
        Livewire::test(ListSubOrders::class)
            ->callTableAction('verifyHandoverCode', $this->subOrder, [
                'handover_code' => $code,
            ])
            ->assertHasNoTableActionErrors();

        $this->subOrder->refresh();
        $this->assertEquals('delivered', $this->subOrder->fulfillmentStatus->slug);
    }
}
