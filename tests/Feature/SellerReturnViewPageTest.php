<?php

namespace Tests\Feature;

use App\Filament\Seller\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Filament\Seller\Resources\ReturnRequests\Pages\ViewReturnRequest;
use App\Models\FulfillmentStatus;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerReturnViewPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SubOrder $parcel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->parcel = SubOrder::where('sub_order_number', 'PKG-20261028-001')->firstOrFail();
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id')]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function askForReturn(string $action): ReturnRequest
    {
        return app(OrderDisputeService::class)->submitReturnRequest($this->parcel->fresh(), $this->parcel->order->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => $action,
            'description' => 'Two nails were cracked in the box.',
        ]);
    }

    public function test_an_open_refund_request_explains_what_each_choice_does(): void
    {
        $request = $this->askForReturn('refund');
        $this->actingAs($this->seller);

        Livewire::test(ViewReturnRequest::class, ['record' => $request->getRouteKey()])
            ->assertOk()
            ->assertSee('The buyer wants a refund')
            ->assertSee('It arrived damaged')
            ->assertSee('Two nails were cracked in the box.')
            ->assertSee('If you approve')
            ->assertSee('If you reject')
            ->assertSee($this->parcel->items()->first()->product->title)
            ->assertActionVisible('approve')
            ->assertActionVisible('reject');
    }

    public function test_the_seller_can_reject_from_the_return_page_and_sees_the_outcome(): void
    {
        $request = $this->askForReturn('replacement');
        $this->actingAs($this->seller);

        Livewire::test(ViewReturnRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('reject', ['note' => 'The photos show the box was opened and used.'])
            ->assertHasNoActionErrors()
            ->assertSee('You rejected this return')
            ->assertActionHidden('approve');

        $this->assertSame(ReturnRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_an_approved_replacement_points_to_the_free_parcel(): void
    {
        $request = $this->askForReturn('replacement');
        $this->actingAs($this->seller);

        Livewire::test(ViewReturnRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('approve', ['note' => ''])
            ->assertHasNoActionErrors()
            ->assertSee('Replacement approved');

        $replacement = SubOrder::where('replaces_return_request_id', $request->id)->firstOrFail();
        Livewire::test(ViewReturnRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee($replacement->sub_order_number);
    }

    public function test_the_list_opens_on_returns_that_need_an_answer(): void
    {
        $open = $this->askForReturn('refund');
        $this->actingAs($this->seller);

        Livewire::test(ListReturnRequests::class)
            ->assertSet('activeTab', 'pending')
            ->assertCanSeeTableRecords([$open])
            ->assertSee('Needs your answer');
    }

    public function test_another_seller_cannot_open_the_return(): void
    {
        $request = $this->askForReturn('refund');
        $rival = User::factory()->create(['role_id' => $this->seller->role_id, 'is_active' => true]);
        $rival->sellerProfile()->create(['store_name' => 'Rival Factory', 'store_slug' => 'rival-factory'])->forceFill(['is_approved' => true])->save();
        $this->actingAs($rival->fresh());

        $this->get(route('filament.seller.resources.return-requests.view', $request))->assertNotFound();
    }
}
