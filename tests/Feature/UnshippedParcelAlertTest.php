<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\Orders\ParcelsAwaitingDispatch;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 2.2 (AR-16): parcels that could ship but haven't after 3 days are flagged once, to the seller by SMS and
 * to admins in the bell, so a forgotten COD order doesn't hold stock for ever.
 */
class UnshippedParcelAlertTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private SubOrder $parcel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        Queue::fake();
        Notification::fake();

        $this->admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id, 'is_active' => true]);
        $this->order = MixedOrderFixture::create('cod', 'unpaid');
        $this->parcel = $this->order->subOrders->firstWhere('subtotal', 500);
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
    }

    private function age(SubOrder $subOrder, int $hours): void
    {
        $subOrder->forceFill(['created_at' => now()->subHours($hours)])->save();
    }

    private function sellerSmsCount(): int
    {
        return Queue::pushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->purpose === 'seller_unshipped_reminder')->count();
    }

    public function test_a_cod_parcel_unshipped_for_three_days_alerts_the_seller_and_admins_once(): void
    {
        $this->age($this->parcel, 73);

        $this->artisan('orders:alert-unshipped-parcels')->assertSuccessful();

        $this->assertSame(1, $this->sellerSmsCount());
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => str_contains($job->message, $this->parcel->sub_order_number) && str_contains($job->message, '3 days'));
        Notification::assertSentTo($this->admin, ParcelsAwaitingDispatch::class, fn (ParcelsAwaitingDispatch $n) => $n->subOrderNumbers === [$this->parcel->sub_order_number]);
        $this->assertNotNull($this->parcel->fresh()->unshipped_alerted_at);

        // The next run says nothing new
        $this->artisan('orders:alert-unshipped-parcels')->assertSuccessful();
        $this->assertSame(1, $this->sellerSmsCount());
        Notification::assertSentToTimes($this->admin, ParcelsAwaitingDispatch::class, 1);
    }

    public function test_a_packed_parcel_still_counts_as_unshipped(): void
    {
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'processing')->value('id')]);
        $this->age($this->parcel, 80);

        $this->artisan('orders:alert-unshipped-parcels')->assertSuccessful();

        $this->assertSame(1, $this->sellerSmsCount());
    }

    public function test_young_shipped_cancelled_and_unpaid_parcels_are_left_alone(): void
    {
        $this->age($this->parcel, 60);
        $shipped = $this->order->subOrders->firstWhere('subtotal', 1000);
        $this->age($shipped, 100);

        $unpaid = MixedOrderFixture::create('bkash', 'awaiting_verification')->subOrders->first();
        $unpaid->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $this->age($unpaid, 100);

        $cancelled = MixedOrderFixture::create('cod', 'unpaid')->subOrders->first();
        $cancelled->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'cancelled')->value('id')]);
        $this->age($cancelled, 100);

        $this->artisan('orders:alert-unshipped-parcels')->assertSuccessful();

        $this->assertSame(0, $this->sellerSmsCount());
        Notification::assertNothingSent();
    }

    public function test_a_paid_online_order_is_alerted_too(): void
    {
        $paid = MixedOrderFixture::create('bkash', 'paid')->subOrders->first();
        $paid->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $this->age($paid, 73);

        $this->artisan('orders:alert-unshipped-parcels')->assertSuccessful();

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => str_contains($job->message, $paid->sub_order_number));
    }

    public function test_the_alert_runs_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('orders:alert-unshipped-parcels');
    }
}
