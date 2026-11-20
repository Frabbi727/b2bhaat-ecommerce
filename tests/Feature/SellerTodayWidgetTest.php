<?php

namespace Tests\Feature;

use App\Filament\Seller\Widgets\SellerTodayWidget;
use App\Models\FulfillmentStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SellerInvoice;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SellerTodayWidgetTest extends TestCase
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

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
    }

    private function setStatus(string $slug): void
    {
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $slug)->value('id')]);
    }

    public function test_a_cash_on_delivery_order_waiting_to_be_packed_is_a_to_do(): void
    {
        $this->setStatus('unfulfilled');

        Livewire::test(SellerTodayWidget::class)
            ->assertOk()
            ->assertSee('To do today')
            ->assertSee('order to pack')
            ->assertDontSee('All caught up');
    }

    public function test_an_unpaid_online_order_is_not_a_to_do_until_the_payment_is_confirmed(): void
    {
        $this->setStatus('unfulfilled');
        $this->parcel->order->update([
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'awaiting_verification')->value('id'),
        ]);
        SubOrder::where('seller_id', $this->seller->id)->whereKeyNot($this->parcel->id)
            ->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id')]);

        Livewire::test(SellerTodayWidget::class)
            ->assertDontSee('to pack')
            ->assertSee('All caught up');
    }

    public function test_another_sellers_orders_never_show(): void
    {
        $this->setStatus('unfulfilled');
        $rival = User::factory()->create(['role_id' => $this->seller->role_id, 'is_active' => true]);
        $rival->sellerProfile()->create(['store_name' => 'Rival Factory', 'store_slug' => 'rival-factory'])->forceFill(['is_approved' => true])->save();
        $this->actingAs($rival->fresh());

        Livewire::test(SellerTodayWidget::class)
            ->assertDontSee('to pack')
            ->assertSee('All caught up');
    }

    public function test_an_overdue_bill_and_vacation_mode_are_shown_as_warnings(): void
    {
        SellerInvoice::create([
            'invoice_number' => 'INV-TEST-1',
            'seller_id' => $this->seller->id,
            'invoice_type' => SellerInvoice::TYPE_MANUAL_FEE,
            'subtotal' => 500,
            'total_amount' => 500,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);
        $this->seller->sellerProfile->update(['is_on_vacation' => true]);

        Livewire::test(SellerTodayWidget::class)
            ->assertSee('Overdue bill: ৳500.00')
            ->assertSee('Vacation mode is on');
    }

    public function test_the_widget_stays_cheap_to_load(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(SellerTodayWidget::class)->assertOk();

        $this->assertLessThanOrEqual(20, count(DB::getQueryLog()));
    }
}
