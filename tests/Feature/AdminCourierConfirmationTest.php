<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\CourierParcels\CourierParcelResource;
use App\Filament\Admin\Resources\CourierParcels\Pages\ListCourierParcels;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Logistics\CourierBookingService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * No courier is connected yet (owner decision 2026-11-05): the admin records what the courier's own panel shows,
 * and the made-up "Book Courier Pickup" numbers never reach a live site.
 */
class AdminCourierConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private SubOrder $courierParcel;

    private SubOrder $selfDeliveredParcel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $order = MixedOrderFixture::create();
        $shipped = FulfillmentStatus::where('slug', 'shipped')->value('id');
        $this->courierParcel = $order->subOrders->firstWhere('subtotal', 500);
        $this->courierParcel->update(['fulfillment_status_id' => $shipped, 'courier_name' => 'Steadfast Courier', 'tracking_number' => 'SF-123']);
        $this->selfDeliveredParcel = $order->subOrders->firstWhere('subtotal', 1000);
        $this->selfDeliveredParcel->update(['fulfillment_status_id' => $shipped]);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_marks_a_courier_parcel_delivered_with_a_note_and_the_normal_settlement_runs(): void
    {
        Livewire::test(ListCourierParcels::class)
            ->assertCanSeeTableRecords([$this->courierParcel])
            ->assertCanNotSeeTableRecords([$this->selfDeliveredParcel])
            ->callTableAction('recordCourierUpdate', $this->courierParcel, [
                'status' => 'delivered',
                'note' => 'Steadfast panel shows Delivered at 3:10 PM',
            ])
            ->assertHasNoTableActionErrors();

        $parcel = $this->courierParcel->fresh();
        $this->assertSame('delivered', $parcel->fulfillmentStatus->slug);
        $this->assertSame('paid', $parcel->order->fresh()->paymentStatus->slug, 'COD is collected by the courier at delivery');

        $log = OrderTrackingLog::where('sub_order_id', $parcel->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Steadfast panel shows Delivered at 3:10 PM', $log->description);
        $this->assertStringContainsString('admin', strtolower($log->title.' '.$log->description));
    }

    public function test_note_is_required(): void
    {
        Livewire::test(ListCourierParcels::class)
            ->callTableAction('recordCourierUpdate', $this->courierParcel, ['status' => 'delivered', 'note' => ''])
            ->assertHasTableActionErrors(['note']);

        $this->assertSame('shipped', $this->courierParcel->fresh()->fulfillmentStatus->slug);
    }

    public function test_sellers_cannot_open_the_courier_confirmation_page(): void
    {
        $this->actingAs(User::findOrFail($this->courierParcel->seller_id));

        $this->assertFalse(CourierParcelResource::canAccess());
    }

    public function test_made_up_courier_bookings_are_refused_on_the_live_site(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(DomainException::class);
        app(CourierBookingService::class)->schedulePickup($this->selfDeliveredParcel, [
            'courier_name' => 'Steadfast Courier', 'pickup_slot' => 'Today', 'pickup_address' => 'Dhaka',
        ]);
    }

    public function test_seller_does_not_see_the_book_pickup_button_on_the_live_site(): void
    {
        $unfulfilled = FulfillmentStatus::where('slug', 'unfulfilled')->value('id');
        $this->courierParcel->update(['fulfillment_status_id' => $unfulfilled]);
        $this->actingAs(User::findOrFail($this->courierParcel->seller_id));
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->app->detectEnvironment(fn () => 'production');

        Livewire::test(ListSubOrders::class)
            ->assertTableActionHidden('bookCourierPickup', $this->courierParcel)
            ->assertTableActionVisible('dispatchCourier', $this->courierParcel);
    }
}
