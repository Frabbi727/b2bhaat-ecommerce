<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\RfqRequests\Pages\EditRfqRequest;
use App\Filament\Seller\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-012: a seller may only quote on an RFQ addressed to them — never reassign it or alter the buyer's request.
 */
class SellerRfqFormTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private RfqRequest $rfq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('seller_id', $this->seller->id)->firstOrFail();

        $this->rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => 'Boutique Buyer',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '01711000000',
            'target_quantity' => 500,
            'target_unit_price' => 80,
            'destination_district' => 'Dhaka',
            'status' => 'pending',
        ]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    public function test_seller_cannot_change_rfq_seller_or_buyer(): void
    {
        Livewire::test(EditRfqRequest::class, ['record' => $this->rfq->getRouteKey()])
            ->assertFormFieldDoesNotExist('seller_id')
            ->assertFormFieldDoesNotExist('buyer_id')
            ->assertFormFieldDoesNotExist('product_id')
            ->assertFormFieldIsDisabled('buyer_name')
            ->assertFormFieldIsDisabled('target_quantity')
            ->fillForm(['buyer_name' => 'Someone Else', 'target_quantity' => 1])
            ->call('save');

        $this->rfq->refresh();
        $this->assertSame($this->seller->id, $this->rfq->seller_id);
        $this->assertSame('Boutique Buyer', $this->rfq->buyer_name);
        $this->assertSame(500, (int) $this->rfq->target_quantity);
    }

    public function test_seller_can_quote_price(): void
    {
        Livewire::test(EditRfqRequest::class, ['record' => $this->rfq->getRouteKey()])
            ->fillForm(['seller_quoted_price' => 72.5, 'seller_notes' => 'Valid 14 days', 'status' => 'quoted'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->rfq->refresh();
        $this->assertEquals(72.5, (float) $this->rfq->seller_quoted_price);
        $this->assertSame('quoted', $this->rfq->status);
    }

    public function test_seller_cannot_set_status_to_accepted(): void
    {
        Livewire::test(EditRfqRequest::class, ['record' => $this->rfq->getRouteKey()])
            ->fillForm(['seller_quoted_price' => 70, 'status' => 'accepted'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame('pending', $this->rfq->fresh()->status);
    }

    public function test_create_page_absent_and_accepted_rfq_is_locked(): void
    {
        $this->get('/seller/rfq-requests/create')->assertNotFound();

        $this->rfq->update(['status' => 'accepted', 'seller_quoted_price' => 70]);

        Livewire::test(ListRfqRequests::class)
            ->assertActionHidden(TestAction::make('send_quote')->table($this->rfq))
            ->assertActionHidden(TestAction::make('edit')->table($this->rfq))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        $this->assertFalse($this->seller->can('update', $this->rfq));
        $this->assertFalse($this->seller->can('delete', $this->rfq));
    }

    public function test_other_seller_cannot_edit_rfq(): void
    {
        $other = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $this->assertFalse($other->can('update', $this->rfq));
    }
}
