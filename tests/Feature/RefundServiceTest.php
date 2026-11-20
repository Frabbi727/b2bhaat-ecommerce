<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Refunds\Pages\ListRefunds;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Orders\OrderDisputeService;
use App\Services\Orders\RefundService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 3.2 + 3.3 (AR-05, AR-15): refunds are paid through one service; a transfer reference settles one refund per
 * method; an order says "Refunded" only once the money has actually been sent, and "Partially refunded" when only
 * part of it was.
 */
class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->order = MixedOrderFixture::create('bkash', 'paid');
        $this->order->subOrders()->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
    }

    private function paymentStatus(): ?string
    {
        return $this->order->fresh()->paymentStatus?->slug;
    }

    /**
     * @return array{0: Refund, 1: Refund}
     */
    private function cancelBothParcels(): array
    {
        foreach ($this->order->subOrders()->get() as $parcel) {
            app(OrderDisputeService::class)->cancelSubOrder($parcel, 'Changed mind');
        }

        return Refund::orderBy('id')->get()->all();
    }

    public function test_a_cancelled_paid_order_is_refund_pending_until_every_refund_is_sent(): void
    {
        [$first, $second] = $this->cancelBothParcels();

        $this->assertSame('cancelled', $this->order->fresh()->orderStatus?->slug);
        $this->assertSame('refund_pending', $this->paymentStatus(), 'owed back, nothing sent yet');

        app(RefundService::class)->markPaid($first, 'bkash', 'BK-RF-1');
        $this->assertSame('refund_pending', $this->paymentStatus(), 'one refund is still to be sent');

        app(RefundService::class)->markPaid($second, 'bkash', 'BK-RF-2');
        $this->assertSame('refunded', $this->paymentStatus());
    }

    public function test_one_parcel_refunded_out_of_two_is_a_partial_refund(): void
    {
        app(OrderDisputeService::class)->cancelSubOrder($this->order->subOrders()->firstOrFail(), 'Changed mind');
        $this->assertSame('refund_pending', $this->paymentStatus());

        app(RefundService::class)->markPaid(Refund::sole(), 'nagad', 'NG-777');

        $this->assertSame('partially_refunded', $this->paymentStatus());
    }

    public function test_the_rest_of_a_refund_pending_order_still_counts_as_paid(): void
    {
        [$first, $second] = $this->order->subOrders()->orderBy('id')->get()->all();
        app(OrderDisputeService::class)->cancelSubOrder($first, 'Changed mind');
        $this->assertSame('refund_pending', $this->paymentStatus());

        $this->assertTrue($second->fresh()->isClearedForDispatch(), 'the other parcel can still ship');

        app(OrderDisputeService::class)->cancelSubOrder($second->fresh(), 'Changed mind');
        $this->assertSame(2, Refund::where('order_id', $this->order->id)->count(), 'its refund is recorded too');
    }

    public function test_the_same_reference_cannot_settle_two_refunds_on_one_method(): void
    {
        [$first, $second] = $this->cancelBothParcels();
        app(RefundService::class)->markPaid($first, 'bkash', 'BK-RF-77');

        try {
            app(RefundService::class)->markPaid($second, 'bkash', 'bk rf 77');
            $this->fail('A transfer reference settled two refunds.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already used', $e->getMessage());
        }
        $this->assertTrue($second->fresh()->isPending());

        // The same text on another method is a different transfer
        app(RefundService::class)->markPaid($second, 'bank', 'BK-RF-77');
        $this->assertFalse($second->fresh()->isPending());
    }

    public function test_paying_a_refund_twice_does_nothing_the_second_time(): void
    {
        [$first] = $this->cancelBothParcels();

        $this->assertTrue(app(RefundService::class)->markPaid($first, 'bkash', 'BK-RF-5'));
        $this->assertFalse(app(RefundService::class)->markPaid($first, 'bkash', 'BK-RF-6'));

        $this->assertSame('BK-RF-5', $first->fresh()->reference);
    }

    public function test_admin_sees_a_reused_reference_as_an_error(): void
    {
        [$first, $second] = $this->cancelBothParcels();
        app(RefundService::class)->markPaid($first, 'bkash', 'BK-RF-9');
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListRefunds::class)
            ->callAction(TestAction::make('markPaid')->table($second), ['method' => 'bkash', 'reference' => 'BK-RF-9'])
            ->assertNotified('Refund not marked as paid');

        $this->assertTrue($second->fresh()->isPending());
    }
}
