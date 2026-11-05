<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Refunds\Pages\ListRefunds;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * PRA-027: money owed back to a customer is recorded as a Refund (method/timing are owner question Q8).
 */
class CustomerRefundRecordTest extends TestCase
{
    use RefreshDatabase;

    private OrderDisputeService $disputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->disputes = app(OrderDisputeService::class);
    }

    private function subOrders(Order $order, string $fulfillmentSlug): array
    {
        $order->subOrders()->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', $fulfillmentSlug)->value('id')]);
        $order->load('subOrders');

        return [$order->subOrders->firstWhere('subtotal', 1000), $order->subOrders->firstWhere('subtotal', 500)];
    }

    public function test_partial_cancel_of_paid_order_creates_one_refund_for_that_sub_order(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        [$a] = $this->subOrders($order, 'unfulfilled');

        $this->disputes->cancelSubOrder($a, 'Changed mind');

        $refund = Refund::sole();
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($a->id, $refund->sub_order_id);
        $this->assertEqualsWithDelta(1036.67, (float) $refund->amount, 0.001);
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
    }

    public function test_cancelling_all_sub_orders_of_paid_order_refunds_order_total(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        $this->subOrders($order, 'unfulfilled');

        $this->disputes->cancelFullOrder($order->fresh('subOrders'), 'Out of budget');

        $this->assertSame(2, Refund::count());
        $this->assertEqualsWithDelta((float) $order->total_amount, (float) Refund::sum('amount'), 0.001);
    }

    public function test_cod_unpaid_cancel_creates_no_refund(): void
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        [$a] = $this->subOrders($order, 'unfulfilled');

        $this->disputes->cancelSubOrder($a, 'Changed mind');

        $this->assertSame(0, Refund::count());
    }

    public function test_approved_return_creates_refund(): void
    {
        $order = MixedOrderFixture::create('cod', 'paid');
        [, $b] = $this->subOrders($order, 'delivered');
        $customer = User::findOrFail($order->customer_id);

        $request = $this->disputes->submitReturnRequest($b, $customer, [
            'reason' => 'Damaged', 'requested_action' => 'refund', 'description' => 'Box crushed',
        ]);
        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Approved');
        // Resolving the same request again must not record a second refund
        $this->disputes->resolveReturnRequest($request->fresh(), ReturnRequest::STATUS_APPROVED, 'Approved again');

        $refund = Refund::sole();
        $this->assertSame($b->id, $refund->sub_order_id);
        $this->assertEqualsWithDelta(553.33, (float) $refund->amount, 0.001);
    }

    public function test_prepaid_refund_excludes_allocated_coupon_share(): void
    {
        $order = MixedOrderFixture::create('nagad', 'paid');
        [, $b] = $this->subOrders($order, 'unfulfilled');

        $this->disputes->cancelSubOrder($b, 'Duplicate order');

        // 500 goods + 70 delivery − 16.67 coupon share
        $this->assertEqualsWithDelta(553.33, (float) Refund::sole()->amount, 0.001);
        $this->assertEqualsWithDelta($b->paidAmount(), (float) Refund::sole()->amount, 0.001);
    }

    public function test_admin_marks_refund_paid(): void
    {
        $order = MixedOrderFixture::create('bkash', 'paid');
        [$a] = $this->subOrders($order, 'unfulfilled');
        $this->disputes->cancelSubOrder($a, 'Changed mind');
        $refund = Refund::sole();

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListRefunds::class)
            ->assertCanSeeTableRecords([$refund])
            ->callAction(TestAction::make('markPaid')->table($refund), ['method' => 'bkash', 'reference' => 'BK-REF-9'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('markPaid')->table($refund->fresh()));

        $refund->refresh();
        $this->assertSame(Refund::STATUS_PAID, $refund->status);
        $this->assertSame('BK-REF-9', $refund->reference);
        $this->assertNotNull($refund->paid_at);
    }

    public function test_return_with_exchange_records_no_refund(): void
    {
        $order = MixedOrderFixture::create('cod', 'paid');
        [, $b] = $this->subOrders($order, 'delivered');

        $request = $this->disputes->submitReturnRequest($b, User::findOrFail($order->customer_id), [
            'reason' => 'Wrong size', 'requested_action' => 'exchange', 'description' => 'Need L',
        ]);
        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Exchange approved');

        $this->assertSame(0, Refund::count());
    }
}
