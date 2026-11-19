<?php

declare(strict_types=1);

namespace Tests\Feature\WholesaleAudit;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\ProductVariation;
use App\Models\ReturnRequest;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wholesale audit 2026-11-14, post-order lifecycle probes (report E part 2).
 */
#[Group('wholesale-audit')]
class LifecycleProbeTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $customer;

    private OrderItem $line;

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
        $number = 'ORD-AUDIT-'.uniqid();
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
        $variation = ProductVariation::orderBy('id')->firstOrFail();
        $this->line = OrderItem::create(['sub_order_id' => $sub->id, 'product_id' => $variation->product_id, 'variation_id' => $variation->id, 'sales_channel_id' => SalesChannel::where('slug', 'retail')->value('id'), 'unit_price' => 100.00, 'quantity' => 10, 'total_price' => 1000.00, 'commission_rate' => 10.00]);

        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
        app(EscrowLedgerService::class)->holdInEscrow($sub);
        app(CourierStatusService::class)->updateStatus($sub, 'delivered');

        return $sub->fresh();
    }

    /**
     * WA-ORD-03 (fixed): an approved replacement takes the units from stock in a ৳0 follow-up parcel, and the seller
     * is not paid for the original until that replacement has been delivered (ReplacementParcelTest covers the rest).
     */
    public function test_wa_ord_03_approved_replacement_takes_stock_and_holds_escrow(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        $stockBefore = (int) ProductVariation::whereKey($this->line->variation_id)->value('stock_quantity');

        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($parcel, $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'replacement',
            'description' => 'Two pieces arrived cracked.',
            'items' => [$this->line->id => 2],
        ]);
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Sending two new pieces');

        $this->assertSame($stockBefore - 2, (int) ProductVariation::whereKey($this->line->variation_id)->value('stock_quantity'), 'the replacement units leave the shelf');
        $this->assertTrue(SubOrder::where('replaces_sub_order_id', $parcel->id)->exists(), 'a follow-up parcel is created');
        $this->assertSame('delivered', $parcel->fresh()->fulfillmentStatus->slug);

        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse(
            WalletTransaction::where('sub_order_id', $parcel->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)->exists(),
            'the seller is paid only once the replacement is delivered'
        );
    }

    /**
     * WA-ORD-02: current behaviour; the fix inverts this assertion.
     * Once the seller rejects a return, nobody can change the decision: the admin resolve call is a no-op.
     */
    public function test_wa_ord_02_seller_rejection_of_a_return_is_final(): void
    {
        $parcel = $this->deliveredPrepaidParcel();
        $disputes = app(OrderDisputeService::class);
        $request = $disputes->submitReturnRequest($parcel, $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Two pieces arrived cracked.',
            'items' => [$this->line->id => 2],
        ]);
        $disputes->resolveReturnRequest($request, ReturnRequest::STATUS_REJECTED, 'Looks fine to me');

        $disputes->resolveReturnRequest($request->fresh(), ReturnRequest::STATUS_APPROVED, 'Admin override');

        $this->assertSame(ReturnRequest::STATUS_REJECTED, $request->fresh()->status, 'today: no escalation path after a seller rejects');
    }
}
