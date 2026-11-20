<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\ProductVariation;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SalesChannel;
use App\Models\StockMovement;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap 3.5 (WA-ORD-03): an approved replacement is a ৳0 follow-up parcel that takes the units from stock, gets its
 * own tracking, and holds the original parcel's payment to the seller until the replacement has been delivered.
 */
class ReplacementParcelTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $customer;

    private OrderItem $line;

    private ProductVariation $variation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
    }

    private function deliveredPrepaidParcel(): SubOrder
    {
        $number = 'ORD-RPL-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'bkash')->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'shipping_amount' => 60.00,
            'discount_amount' => 0,
            'total_amount' => 1060.00,
        ]);
        $sub = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'tracking_number' => 'TRK-'.$number,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 60.00,
        ]);
        $this->variation = ProductVariation::where('product_id', '!=', 0)->orderBy('id')->firstOrFail();
        $this->line = OrderItem::create(['sub_order_id' => $sub->id, 'product_id' => $this->variation->product_id, 'variation_id' => $this->variation->id, 'sales_channel_id' => SalesChannel::where('slug', 'retail')->value('id'), 'unit_price' => 100.00, 'original_unit_price' => 100.00, 'quantity' => 10, 'total_price' => 1000.00, 'commission_rate' => 10.00]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($sub);
        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        return $sub->fresh();
    }

    /**
     * @param  array<int, int>  $items
     */
    private function approveReplacement(SubOrder $parcel, array $items): SubOrder
    {
        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($parcel, $this->customer, array_filter([
            'reason' => 'damaged_goods',
            'requested_action' => 'replacement',
            'description' => 'Two pieces arrived cracked.',
            'items' => $items,
        ]));
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Sending new pieces');

        return SubOrder::where('replaces_sub_order_id', $parcel->id)->firstOrFail();
    }

    private function stock(): int
    {
        return (int) ProductVariation::whereKey($this->variation->id)->value('stock_quantity');
    }

    private function released(SubOrder $parcel): bool
    {
        return WalletTransaction::where('sub_order_id', $parcel->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists();
    }

    public function test_an_approved_replacement_is_a_free_follow_up_parcel_that_takes_stock(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        $stockBefore = $this->stock();

        $replacement = $this->approveReplacement($parcel, [$this->line->id => 2]);

        $this->assertSame('unfulfilled', $replacement->fulfillmentStatus->slug);
        $this->assertSame($parcel->seller_id, $replacement->seller_id);
        $this->assertSame(0.0, (float) $replacement->subtotal);
        $this->assertSame(0.0, $replacement->paidAmount(), 'the buyer pays nothing for it');
        $this->assertSame(2, (int) $replacement->items()->sum('quantity'));
        $this->assertSame($this->variation->id, $replacement->items()->value('variation_id'));

        $this->assertSame($stockBefore - 2, $this->stock(), 'the new pieces leave the shelf');
        $movement = StockMovement::where('product_variation_id', $this->variation->id)->latest('id')->firstOrFail();
        $this->assertSame(StockMovement::REASON_REPLACEMENT, $movement->reason);
        $this->assertTrue($movement->reference->is($replacement));

        $this->assertSame('delivered', $parcel->fresh()->fulfillmentStatus->slug, 'the sale stands');
        $this->assertSame(0, Refund::count(), 'no refund for a replacement');
    }

    public function test_the_seller_is_paid_only_after_the_replacement_is_delivered(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        $replacement = $this->approveReplacement($parcel, [$this->line->id => 2]);

        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse($this->released($parcel), 'the replacement has not reached the buyer');

        $replacement->update(['tracking_number' => 'TRK-RPL-'.$replacement->id, 'courier_name' => 'Steadfast Courier']);
        app(CourierStatusService::class)->updateStatus($replacement->fresh(), 'shipped');
        app(CourierStatusService::class)->updateStatus($replacement->fresh(), 'delivered');
        $this->assertFalse(WalletTransaction::where('sub_order_id', $replacement->id)->exists(), 'a ৳0 parcel moves no money');

        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse($this->released($parcel), 'the buyer gets the usual window to check the replacement');

        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertTrue($this->released($parcel));
    }

    public function test_a_replacement_without_stock_is_refused_and_nothing_changes(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        ProductVariation::whereKey($this->variation->id)->update(['stock_quantity' => 1]);

        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($parcel, $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'replacement',
            'description' => 'Two pieces arrived cracked.',
            'items' => [$this->line->id => 2],
        ]);

        try {
            $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Sending new pieces');
            $this->fail('A replacement was approved without the stock to send it.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('stock', $e->getMessage());
        }

        $this->assertSame(ReturnRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(1, $this->stock());
        $this->assertFalse(SubOrder::where('replaces_sub_order_id', $parcel->id)->exists());
    }

    public function test_a_replacement_for_the_whole_parcel_resends_every_item(): void
    {
        $parcel = $this->deliveredPrepaidParcel();

        $replacement = $this->approveReplacement($parcel, []);

        $this->assertSame(10, (int) $replacement->items()->sum('quantity'));
    }

    public function test_cancelling_a_replacement_puts_the_stock_back_and_records_no_refund(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        $stockBefore = $this->stock();
        $replacement = $this->approveReplacement($parcel, [$this->line->id => 2]);

        app(OrderDisputeService::class)->cancelSubOrder($replacement, 'Seller will refund instead');

        $this->assertSame($stockBefore, $this->stock());
        $this->assertSame(0, Refund::count(), 'nothing was paid for the replacement');
    }
}
