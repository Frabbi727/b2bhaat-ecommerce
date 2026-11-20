<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap 1.5: courier status changes follow the same payment rule as hand-over (AR-03), and a delivered parcel can
 * be sent back only inside the return window unless an admin records it (AR-02).
 */
class CourierStatusGatesTest extends TestCase
{
    use RefreshDatabase;

    private SubOrder $subOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->subOrder = SubOrder::firstOrFail();
        $this->subOrder->update([
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id'),
            'tracking_number' => 'STDFST-GATE-1',
        ]);
    }

    private function makeUnpaidMobileWalletOrder(): void
    {
        $this->subOrder->order->update([
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'awaiting_verification')->value('id'),
        ]);
    }

    private function statusSlug(): ?string
    {
        return $this->subOrder->fresh()->fulfillmentStatus?->slug;
    }

    private function deliver(): void
    {
        app(CourierStatusService::class)->updateStatus($this->subOrder, 'shipped');
        app(CourierStatusService::class)->updateStatus($this->subOrder, 'delivered');
    }

    public function test_unpaid_mobile_wallet_parcel_cannot_be_shipped_or_delivered(): void
    {
        $this->makeUnpaidMobileWalletOrder();

        foreach (['shipped', 'in_transit'] as $status) {
            try {
                app(CourierStatusService::class)->updateStatus($this->subOrder, $status);
                $this->fail("An unpaid parcel was moved to {$status}.");
            } catch (DomainException $e) {
                $this->assertStringContainsString("payment hasn't been confirmed", $e->getMessage());
            }
        }

        $this->assertSame('unfulfilled', $this->statusSlug());
        $this->assertSame(0, WalletTransaction::where('sub_order_id', $this->subOrder->id)->count(), 'no escrow is held');
    }

    /**
     * A parcel shipped while unpaid before this rule existed still can't be delivered, so no escrow is held for it.
     */
    public function test_unpaid_parcel_already_shipped_cannot_be_delivered(): void
    {
        $this->makeUnpaidMobileWalletOrder();
        $this->subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'shipped')->value('id')]);

        $this->expectException(DomainException::class);

        try {
            app(CourierStatusService::class)->updateStatus($this->subOrder, 'delivered');
        } finally {
            $this->assertSame('shipped', $this->statusSlug());
            $this->assertSame(0, WalletTransaction::where('sub_order_id', $this->subOrder->id)->count());
        }
    }

    public function test_courier_webhook_for_an_unpaid_parcel_is_refused_without_a_server_error(): void
    {
        $this->makeUnpaidMobileWalletOrder();

        $this->signedCourierPost(['tracking_number' => 'STDFST-GATE-1', 'status' => 'shipped'])
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame('unfulfilled', $this->statusSlug());
    }

    public function test_cod_parcel_still_moves_through_courier_statuses(): void
    {
        $this->deliver();

        $this->assertSame('delivered', $this->statusSlug());
    }

    public function test_courier_can_return_a_delivered_parcel_inside_the_return_window(): void
    {
        $this->deliver();
        $this->travel(47)->hours();

        app(CourierStatusService::class)->updateStatus($this->subOrder, 'returned');

        $this->assertSame('returned', $this->statusSlug());
    }

    public function test_courier_cannot_return_a_delivered_parcel_after_the_return_window(): void
    {
        $this->deliver();
        $this->travel(49)->hours();

        $this->signedCourierPost(['tracking_number' => 'STDFST-GATE-1', 'status' => 'returned'])
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame('delivered', $this->statusSlug());
    }

    public function test_admin_can_record_a_late_return_with_a_reason(): void
    {
        $this->deliver();
        $this->travel(49)->hours();

        app(CourierStatusService::class)->updateStatus(
            subOrder: $this->subOrder,
            statusSlug: 'returned',
            description: 'Courier panel shows the parcel came back to the seller',
            adminOverride: true,
        );

        $this->assertSame('returned', $this->statusSlug());
    }
}
