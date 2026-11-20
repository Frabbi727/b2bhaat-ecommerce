<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 5.7 (WA-BUY-03): the buyer's order page says whether a refund is still owed or has been paid, and how long
 * a parcel can still be cancelled.
 */
class BuyerRefundStatusTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->order = MixedOrderFixture::create('cod', 'unpaid');
        $this->actingAs(User::findOrFail($this->order->customer_id));
    }

    private function page(): Testable
    {
        return Livewire::test(OrderTracking::class)
            ->set('orderNumber', $this->order->order_number)
            ->call('searchOrder');
    }

    public function test_a_refund_shows_as_owed_then_as_paid(): void
    {
        $parcel = $this->order->subOrders->first();
        $refund = Refund::create([
            'order_id' => $this->order->id,
            'sub_order_id' => $parcel->id,
            'amount' => 450.00,
            'reason' => 'Parcel cancelled',
            'status' => Refund::STATUS_PENDING,
        ]);

        $this->page()->assertSee('Refund owed to you: ৳450.00.')->assertDontSee('Refund paid');

        $refund->update(['status' => Refund::STATUS_PAID, 'method' => 'bkash', 'reference' => 'TRX123', 'paid_at' => now()]);

        $this->page()->assertSee('Refund paid: ৳450.00')->assertSee('by bKash')->assertSee('TRX123')->assertDontSee('Refund owed to you');
    }

    public function test_an_unpacked_parcel_says_it_can_be_cancelled_until_packed(): void
    {
        $this->order->subOrders->first()->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);

        $this->page()->assertSee('You can cancel until the seller packs it.');
    }
}
