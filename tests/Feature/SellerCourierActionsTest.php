<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerCourierActionsTest extends TestCase
{
    use RefreshDatabase;

    private SubOrder $subOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->subOrder = SubOrder::where('seller_id', $seller->id)->firstOrFail();
        $this->subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id')]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($seller);
    }

    public function test_seller_cannot_mark_courier_shipment_delivered_or_simulate(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        Livewire::test(ListSubOrders::class)
            ->assertActionDoesNotExist(TestAction::make('markDelivered')->table($this->subOrder))
            ->assertActionHidden(TestAction::make('simulateCourier')->table($this->subOrder));
    }

    public function test_simulate_courier_visible_in_local_only(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        Livewire::test(ListSubOrders::class)
            ->assertActionVisible(TestAction::make('simulateCourier')->table($this->subOrder));
    }

    public function test_self_delivery_handover_action_still_visible(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->subOrder->update([
            'is_self_delivered' => true,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
        ]);

        Livewire::test(ListSubOrders::class)
            ->assertActionVisible(TestAction::make('verifyHandoverCode')->table($this->subOrder));
    }
}
