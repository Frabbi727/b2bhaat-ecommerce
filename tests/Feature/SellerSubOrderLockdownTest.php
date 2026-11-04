<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerSubOrderLockdownTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SubOrder $subOrder;

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

    public function test_seller_cannot_open_sub_order_create_page(): void
    {
        $this->get('/seller/sub-orders/create')->assertNotFound();
    }

    public function test_seller_cannot_open_sub_order_edit_page_and_money_is_unchanged(): void
    {
        $commission = (float) $this->subOrder->commission_amount;

        $this->get("/seller/sub-orders/{$this->subOrder->getRouteKey()}/edit")->assertNotFound();

        $this->assertEqualsWithDelta($commission, (float) $this->subOrder->fresh()->commission_amount, 0.001);
    }

    public function test_seller_can_view_own_sub_order(): void
    {
        $this->get("/seller/sub-orders/{$this->subOrder->getRouteKey()}")->assertSuccessful();
    }

    public function test_seller_cannot_bulk_delete_or_edit_sub_orders(): void
    {
        Livewire::test(ListSubOrders::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->assertActionDoesNotExist(TestAction::make('edit')->table($this->subOrder));
    }

    public function test_policy_denies_seller_writes(): void
    {
        $this->assertTrue($this->seller->can('view', $this->subOrder));
        $this->assertFalse($this->seller->can('create', SubOrder::class));
        $this->assertFalse($this->seller->can('update', $this->subOrder));
        $this->assertFalse($this->seller->can('delete', $this->subOrder));

        $otherSeller = User::factory()->create(['role_id' => $this->seller->role_id]);
        $this->assertFalse($otherSeller->can('view', $this->subOrder));
    }
}
