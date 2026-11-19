<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Jobs\SendSmsJob;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Models\ProductVariation;
use App\Models\Refund;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Orders\OrderDisputeService;
use App\Services\Orders\ParcelCancellationService;
use App\Services\Payments\PaymentVerificationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Exception;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 2.1 + 2.2 (WA-ORD-01, AR-12, AR-16): one cancellation path for buyer, seller, admin and payment expiry; the
 * seller can decline or mark a parcel packed, and an admin can cancel a parcel that hasn't left the seller.
 */
class ParcelCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private SubOrder $parcel;

    private User $seller;

    private ProductVariation $variation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        Queue::fake();

        $this->order = MixedOrderFixture::create('cod', 'unpaid');
        $this->order->update(['tracking_token' => 'tok-'.str_repeat('c', 30)]);
        $this->parcel = $this->order->subOrders->firstWhere('subtotal', 500);
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id'), 'tracking_number' => null]);
        // The other parcel is not yet shipped either, so cancelling one leaves the order open
        $this->order->subOrders->firstWhere('subtotal', 1000)->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $this->seller = User::findOrFail($this->parcel->seller_id);

        $this->variation = ProductVariation::query()->orderBy('id')->firstOrFail();
        // 10 in stock before the order; checkout took the 2 below
        $this->variation->update(['stock_quantity' => 8]);
        OrderItem::create([
            'sub_order_id' => $this->parcel->id,
            'product_id' => $this->variation->product_id,
            'variation_id' => $this->variation->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->value('id'),
            'unit_price' => 250.00,
            'quantity' => 2,
            'total_price' => 500.00,
            'commission_rate' => 10,
        ]);
    }

    private function statusOf(SubOrder $subOrder): ?string
    {
        return $subOrder->fresh()->fulfillmentStatus?->slug;
    }

    private function actAsSeller(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id, 'is_active' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    public function test_seller_declines_a_parcel_with_a_reason(): void
    {
        $this->actAsSeller();

        Livewire::test(ListSubOrders::class)
            ->callAction(TestAction::make('declineParcel')->table($this->parcel), [
                'reason' => 'out_of_stock',
                'note' => 'Colour discontinued',
            ])
            ->assertHasNoActionErrors();

        $parcel = $this->parcel->fresh();
        $this->assertSame('cancelled', $this->statusOf($parcel));
        $this->assertSame(ParcelCancellationService::BY_SELLER, $parcel->cancelled_by);
        $this->assertSame('Out of stock: Colour discontinued', $parcel->cancel_reason);
        $this->assertNotNull($parcel->cancelled_at);
        $this->assertSame(10, (int) $this->variation->fresh()->stock_quantity, 'reserved units go back on sale');
        $this->assertNotSame('cancelled', $this->order->fresh()->orderStatus?->slug, 'the other parcel keeps the order open');
        $this->assertTrue(OrderTrackingLog::where('sub_order_id', $parcel->id)->where('title', 'Declined by Seller')->exists());
        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => str_contains($job->message, 'cancelled by the seller') && str_contains($job->message, 'Out of stock'));
    }

    public function test_seller_cannot_decline_a_parcel_that_has_shipped(): void
    {
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id')]);
        $this->actAsSeller();

        Livewire::test(ListSubOrders::class)->assertActionHidden(TestAction::make('declineParcel')->table($this->parcel));

        $this->expectException(DomainException::class);
        app(ParcelCancellationService::class)->declineBySeller($this->parcel, $this->seller, 'Out of stock');
    }

    public function test_seller_cannot_decline_another_sellers_parcel(): void
    {
        $other = $this->order->subOrders->firstWhere('subtotal', 1000);

        $this->expectException(DomainException::class);
        try {
            app(ParcelCancellationService::class)->declineBySeller($other, $this->seller, 'Out of stock');
        } finally {
            $this->assertSame('unfulfilled', $this->statusOf($other));
        }
    }

    public function test_seller_marks_a_parcel_packed_and_the_buyer_can_no_longer_cancel_it_online(): void
    {
        $this->actAsSeller();

        Livewire::test(ListSubOrders::class)
            ->callAction(TestAction::make('markPacked')->table($this->parcel))
            ->assertHasNoActionErrors();

        $this->assertSame('processing', $this->statusOf($this->parcel));

        try {
            app(OrderDisputeService::class)->cancelSubOrder($this->parcel->fresh(), 'Changed my mind');
            $this->fail('A packed parcel was cancelled by the buyer.');
        } catch (Exception $e) {
            $this->assertStringContainsString('packed', $e->getMessage());
        }
        $this->assertSame('processing', $this->statusOf($this->parcel));
    }

    public function test_packed_parcel_can_still_be_declined_by_the_seller(): void
    {
        app(ParcelCancellationService::class)->markPacked($this->parcel, $this->seller);

        app(ParcelCancellationService::class)->declineBySeller($this->parcel, $this->seller, 'Damaged while packing');

        $this->assertSame('cancelled', $this->statusOf($this->parcel));
    }

    public function test_admin_cancels_a_paid_parcel_and_the_refund_is_recorded(): void
    {
        $this->order->update(['payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id')]);
        $admin = $this->actAsAdmin();

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->callAction('cancelParcel', ['sub_order_id' => $this->parcel->id, 'reason' => 'Seller unreachable for 5 days'])
            ->assertHasNoActionErrors();

        $parcel = $this->parcel->fresh();
        $this->assertSame('cancelled', $this->statusOf($parcel));
        $this->assertSame(ParcelCancellationService::BY_ADMIN, $parcel->cancelled_by);
        $this->assertSame($admin->id, $parcel->cancelled_by_user_id);
        $this->assertSame('Seller unreachable for 5 days', $parcel->cancel_reason);
        $this->assertTrue(Refund::where('sub_order_id', $parcel->id)->exists(), 'the buyer is owed the paid amount');
        $this->assertSame(10, (int) $this->variation->fresh()->stock_quantity);
    }

    public function test_admin_cannot_cancel_a_parcel_that_has_left_the_seller(): void
    {
        $this->parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'in_transit')->value('id')]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Returned to seller');
        app(ParcelCancellationService::class)->cancelByAdmin($this->parcel, $this->actAsAdmin(), 'Buyer asked');
    }

    public function test_cancelling_twice_changes_nothing_the_second_time(): void
    {
        $service = app(ParcelCancellationService::class);

        $this->assertTrue($service->cancel($this->parcel, ParcelCancellationService::BY_BUYER, 'Changed my mind'));
        $this->assertFalse($service->cancel($this->parcel, ParcelCancellationService::BY_ADMIN, 'Again'));

        $this->assertSame(10, (int) $this->variation->fresh()->stock_quantity, 'stock is released once');
        $this->assertSame(ParcelCancellationService::BY_BUYER, $this->parcel->fresh()->cancelled_by);
    }

    public function test_buyer_cancellation_records_who_cancelled(): void
    {
        app(OrderDisputeService::class)->cancelSubOrder($this->parcel, 'Changed my mind');

        $parcel = $this->parcel->fresh();
        $this->assertSame(ParcelCancellationService::BY_BUYER, $parcel->cancelled_by);
        $this->assertSame('Changed my mind', $parcel->cancel_reason);
        $this->assertSame(10, (int) $this->variation->fresh()->stock_quantity);
    }

    public function test_rejected_payment_records_a_system_cancellation(): void
    {
        $this->order->update(['payment_status_id' => PaymentStatus::where('slug', 'awaiting_verification')->value('id')]);

        app(PaymentVerificationService::class)->rejectPayment($this->order, 'TrxID not found');

        $parcel = $this->parcel->fresh();
        $this->assertSame('cancelled', $this->statusOf($parcel));
        $this->assertSame(ParcelCancellationService::BY_SYSTEM, $parcel->cancelled_by);
        $this->assertSame(10, (int) $this->variation->fresh()->stock_quantity);
        $this->assertSame('cancelled', $this->order->fresh()->orderStatus?->slug);
    }
}
