<?php

namespace Tests\Feature;

use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderDisputeAndCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_buyer_can_cancel_unfulfilled_order_and_release_stock(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $order = $subOrder->order;
        $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();

        // Put sub-order into unfulfilled
        $subOrder->update(['fulfillment_status_id' => $unfulfilledStatus->id]);
        $order->update(['order_status_id' => OrderStatus::where('slug', 'pending')->value('id')]);

        $item = $subOrder->items()->firstOrFail();
        $variation = $item->variation;
        $initialStock = $variation->stock_quantity;
        $qtyToRestore = $item->quantity;

        $disputeService = app(OrderDisputeService::class);
        $disputeService->cancelFullOrder($order, 'Buyer requested cancellation');

        // Verify order and sub-order cancelled
        $order->refresh();
        $subOrder->refresh();
        $variation->refresh();

        $this->assertEquals(OrderStatus::where('slug', 'cancelled')->value('id'), $order->order_status_id);
        $this->assertEquals(FulfillmentStatus::where('slug', 'cancelled')->value('id'), $subOrder->fulfillment_status_id);

        // Verify stock restored
        $this->assertEquals($initialStock + $qtyToRestore, $variation->stock_quantity);

        // Verify tracking log created
        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Consignment Cancelled',
        ]);
    }

    public function test_dispatched_sub_order_cannot_be_cancelled(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $shippedStatus = FulfillmentStatus::where('slug', 'shipped')->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => $shippedStatus->id]);

        $disputeService = app(OrderDisputeService::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('has already been dispatched with courier');

        $disputeService->cancelSubOrder($subOrder, 'Late cancellation attempt');
    }

    public function test_delivered_sub_order_can_submit_return_dispute_request(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => $deliveredStatus->id]);

        $customer = $subOrder->order->customer;

        $disputeService = app(OrderDisputeService::class);
        $returnRequest = $disputeService->submitReturnRequest($subOrder, $customer, [
            'reason' => 'defective_item',
            'requested_action' => 'refund',
            'description' => 'The item does not power on out of the box.',
        ]);

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnRequest->id,
            'sub_order_id' => $subOrder->id,
            'customer_id' => $customer->id,
            'reason' => 'defective_item',
            'requested_action' => 'refund',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Return / Dispute Requested',
        ]);
    }

    public function test_seller_can_approve_return_dispute(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $customer = $subOrder->order->customer;
        $statusBefore = $subOrder->fulfillment_status_id;

        $returnRequest = ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $customer->id,
            'reason' => 'damaged_goods',
            'requested_action' => 'replacement',
            'description' => 'Bottle arrived cracked.',
            'status' => ReturnRequest::STATUS_PENDING,
        ]);

        $disputeService = app(OrderDisputeService::class);
        $disputeService->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_APPROVED, 'Approved for free replacement');

        $returnRequest->refresh();
        $subOrder->refresh();

        $this->assertEquals(ReturnRequest::STATUS_APPROVED, $returnRequest->status);
        // A replacement keeps the sale as it was (final-review I1); only refund/return actions move it to returned
        $this->assertEquals($statusBefore, $subOrder->fulfillment_status_id);

        $this->assertDatabaseHas('order_tracking_logs', [
            'sub_order_id' => $subOrder->id,
            'title' => 'Replacement Approved by Supplier',
        ]);
    }

    public function test_seller_can_reject_return_dispute(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $customer = $subOrder->order->customer;

        $returnRequest = ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $customer->id,
            'reason' => 'not_as_described',
            'requested_action' => 'refund',
            'description' => 'Color shade is slightly different from screen.',
            'status' => ReturnRequest::STATUS_PENDING,
        ]);

        $disputeService = app(OrderDisputeService::class);
        $disputeService->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_REJECTED, 'Screen color calibration variance not eligible for refund');

        $returnRequest->refresh();
        $this->assertEquals(ReturnRequest::STATUS_REJECTED, $returnRequest->status);
    }

    public function test_order_tracking_page_submits_return_request(): void
    {
        $subOrder = SubOrder::firstOrFail();
        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->firstOrFail();
        $subOrder->update(['fulfillment_status_id' => $deliveredStatus->id]);
        $customer = $subOrder->order->customer;

        $this->actingAs($customer);

        Livewire::test(OrderTracking::class, ['orderNumber' => $subOrder->order->order_number])
            ->call('openReturnModal', $subOrder->id)
            ->assertSet('showReturnModal', true)
            ->set('returnReason', 'defective_item')
            ->set('returnRequestedAction', 'refund')
            ->set('returnDescription', 'The gel polish had hardened upon receipt.')
            ->call('submitReturnRequest')
            ->assertSet('showReturnModal', false)
            ->assertSee('Return Dispute Pending Review');

        $this->assertDatabaseHas('return_requests', [
            'sub_order_id' => $subOrder->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
    }
}
