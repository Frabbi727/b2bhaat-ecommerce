<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Livewire\Storefront\OrderTracking;
use App\Models\Accounting\AccountingJournalLine;
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
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PAY-4: a buyer can return some items of a parcel. Owner rules (2026-11-10): the refund is the items' price minus
 * their share of the coupon; the commission on those items is given back in proportion; the delivery fee is
 * refunded only when the whole parcel has come back.
 *
 * Parcel: 6 × ৳100 + 2 × ৳200 = ৳1,000, commission ৳100, courier fee ৳60, coupon ৳50 → buyer paid ৳1,010.
 */
class PartialReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $customer;

    private OrderDisputeService $disputes;

    private GeneralLedgerService $ledger;

    private OrderItem $cheapItem;

    private OrderItem $dearItem;

    private float $commission = 100.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->disputes = app(OrderDisputeService::class);
        $this->ledger = app(GeneralLedgerService::class);
    }

    private function deliveredParcel(string $method = 'bkash', bool $selfDelivered = false): SubOrder
    {
        $number = 'ORD-PAY4-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $method === 'cod' ? 'unpaid' : 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $method)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'shipping_amount' => 60.00,
            'discount_amount' => 50.00,
            'coupon_code' => 'SAVE50',
            'total_amount' => 1010.00,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
            'courier_name' => $selfDelivered ? 'Factory Direct Fleet' : 'Steadfast Courier',
            'tracking_number' => 'TRK-'.$number,
            'subtotal' => 1000.00,
            'commission_amount' => $this->commission,
            'shipping_amount' => 60.00,
            'is_self_delivered' => $selfDelivered,
            'handover_code' => '4321',
        ]);

        $variations = ProductVariation::where('product_id', '!=', 0)->orderBy('id')->take(2)->get();
        $this->cheapItem = $this->item($subOrder, $variations[0], 100.00, 6);
        $this->dearItem = $this->item($subOrder, $variations[1], 200.00, 2);

        if ($method !== 'cod') {
            app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($order);
            app(EscrowLedgerService::class)->holdInEscrow($subOrder);
        }

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        if ($method === 'cod' && $selfDelivered) {
            app(EscrowLedgerService::class)->settleSelfCollectedCod($subOrder->fresh());
        }

        return $subOrder->fresh();
    }

    private function item(SubOrder $subOrder, ProductVariation $variation, float $price, int $quantity): OrderItem
    {
        return OrderItem::create([
            'sub_order_id' => $subOrder->id,
            'product_id' => $variation->product_id,
            'variation_id' => $variation->id,
            'sales_channel_id' => SalesChannel::where('slug', 'retail')->value('id'),
            'unit_price' => $price,
            'original_unit_price' => $price,
            'quantity' => $quantity,
            'total_price' => $price * $quantity,
        ]);
    }

    /**
     * @param  array<int, int>  $quantities  order item id => units coming back
     */
    private function returnItems(SubOrder $subOrder, array $quantities): ReturnRequest
    {
        $request = $this->disputes->submitReturnRequest($subOrder->fresh(), $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Some pieces arrived cracked.',
            'items' => $quantities,
        ]);

        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');

        return $request->fresh();
    }

    private function wallet(): SellerWallet
    {
        return SellerWallet::where('seller_id', $this->seller->id)->firstOrFail();
    }

    private function assertBooksBalance(): void
    {
        $this->assertSame(
            round((float) AccountingJournalLine::query()->sum('debit'), 2),
            round((float) AccountingJournalLine::query()->sum('credit'), 2),
        );
    }

    private function commissionLeft(): float
    {
        return round($this->ledger->getBalanceForSlug('commission_revenue') + $this->ledger->getBalanceForSlug('deferred_commission_revenue'), 2);
    }

    public function test_returning_some_items_refunds_their_price_less_their_coupon_share(): void
    {
        $parcel = $this->deliveredParcel();
        $heldBefore = (float) $this->wallet()->escrow_held_balance;
        $stockBefore = (int) ProductVariation::whereKey($this->cheapItem->variation_id)->value('stock_quantity');

        $request = $this->returnItems($parcel, [$this->cheapItem->id => 3]);

        // ৳300 of goods: coupon share ৳15, commission ৳30
        $refund = Refund::where('return_request_id', $request->id)->firstOrFail();
        $this->assertEqualsWithDelta(285.00, (float) $refund->amount, 0.001);
        $this->assertSame($parcel->id, $refund->sub_order_id);

        $line = $request->items()->firstOrFail();
        $this->assertSame(3, (int) $line->quantity);
        $this->assertEqualsWithDelta(285.00, (float) $line->refund_amount, 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $line->commission_amount, 0.001);
        $this->assertEqualsWithDelta(270.00, (float) $line->seller_amount, 0.001);
        $this->assertEqualsWithDelta(15.00, (float) $line->discount_share, 0.001);

        $this->assertEqualsWithDelta($heldBefore - 270.00, (float) $this->wallet()->escrow_held_balance, 0.001);
        $this->assertEqualsWithDelta(630.00, app(EscrowLedgerService::class)->heldAmountFor($parcel), 0.001);
        $this->assertSame('delivered', $parcel->fresh()->fulfillmentStatus->slug, 'Part of the parcel is still with the buyer');
        $this->assertSame($stockBefore + 3, (int) ProductVariation::whereKey($this->cheapItem->variation_id)->value('stock_quantity'));

        $this->assertEqualsWithDelta(285.00, $this->ledger->getBalanceForSlug('customer_refund_payable'), 0.001);
        $this->assertEqualsWithDelta(70.00, $this->commissionLeft(), 0.001);
        $this->assertEqualsWithDelta(60.00, $this->ledger->getBalanceForSlug('shipping_service_revenue'), 0.001, 'The delivery fee stays earned');
        $this->assertBooksBalance();

        // The rest is paid to the seller as usual
        $release = app(EscrowLedgerService::class)->releaseFromEscrow($parcel->fresh());
        $this->assertEqualsWithDelta(630.00, (float) $release->amount, 0.001);
    }

    public function test_the_return_that_brings_back_the_rest_also_refunds_the_delivery_fee(): void
    {
        $parcel = $this->deliveredParcel();
        $heldBefore = (float) $this->wallet()->escrow_held_balance;
        $this->returnItems($parcel, [$this->cheapItem->id => 3]);

        $last = $this->returnItems($parcel, [$this->cheapItem->id => 3, $this->dearItem->id => 2]);

        // ৳700 of goods − ৳35 coupon share + ৳60 delivery fee
        $this->assertEqualsWithDelta(725.00, (float) Refund::where('return_request_id', $last->id)->value('amount'), 0.001);
        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->sum('amount'), 0.001, 'The buyer gets back everything they paid');
        $this->assertSame('returned', $parcel->fresh()->fulfillmentStatus->slug);

        $this->assertEqualsWithDelta($heldBefore - 900.00, (float) $this->wallet()->escrow_held_balance, 0.001);
        $this->assertEqualsWithDelta(0.00, app(EscrowLedgerService::class)->heldAmountFor($parcel), 0.001);
        $this->assertNull(app(EscrowLedgerService::class)->releaseFromEscrow($parcel->fresh()));

        $this->assertEqualsWithDelta(1010.00, $this->ledger->getBalanceForSlug('customer_refund_payable'), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->commissionLeft(), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->ledger->getBalanceForSlug('shipping_service_revenue'), 0.001);
        $this->assertBooksBalance();
    }

    public function test_a_return_after_the_money_was_released_comes_out_of_the_available_balance(): void
    {
        $parcel = $this->deliveredParcel();
        $escrow = app(EscrowLedgerService::class);
        $escrow->releaseFromEscrow($parcel);
        $availableBefore = (float) $this->wallet()->available_balance;

        $request = $this->returnItems($parcel, [$this->dearItem->id => 1]);

        // ৳200 of goods: seller loses ৳180, buyer gets ৳190
        $this->assertEqualsWithDelta($availableBefore - 180.00, (float) $this->wallet()->available_balance, 0.001);
        $this->assertEqualsWithDelta(190.00, (float) Refund::where('return_request_id', $request->id)->value('amount'), 0.001);
        $this->assertSame(1, WalletTransaction::where('return_request_id', $request->id)->count());
        $this->assertEqualsWithDelta(80.00, $this->commissionLeft(), 0.001);
        $this->assertBooksBalance();
    }

    public function test_a_seller_who_collected_the_cash_gets_back_the_commission_share_less_the_coupon_share(): void
    {
        $parcel = $this->deliveredParcel('cod', selfDelivered: true);
        $availableBefore = (float) $this->wallet()->available_balance;
        $owedToBuyersBefore = $this->ledger->getBalanceForSlug('customer_refund_payable');

        $request = $this->returnItems($parcel, [$this->cheapItem->id => 3]);

        // Commission share ৳30 − coupon share ৳15 comes back; item 8: the seller kept the cash, so the buyer's
        // ৳285 refund (৳300 of goods − ৳15 coupon share) is charged to them and paid out by B2Bhaat
        $this->assertEqualsWithDelta($availableBefore + 15.00 - 285.00, (float) $this->wallet()->available_balance, 0.001);
        $this->assertEqualsWithDelta(285.00, (float) Refund::where('return_request_id', $request->id)->value('amount'), 0.001);
        $this->assertEqualsWithDelta($owedToBuyersBefore + 285.00, $this->ledger->getBalanceForSlug('customer_refund_payable'), 0.001);
        $this->assertSame(1, WalletTransaction::where('return_request_id', $request->id)->where('transaction_type', WalletTransaction::TYPE_COD_RETURN_REFUND)->count());
        $this->assertSame('delivered', $parcel->fresh()->fulfillmentStatus->slug);
        $this->assertBooksBalance();
    }

    public function test_only_items_still_with_the_buyer_can_be_returned(): void
    {
        $parcel = $this->deliveredParcel();
        $submit = fn (array $items) => $this->disputes->submitReturnRequest($parcel->fresh(), $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Some pieces arrived cracked.',
            'items' => $items,
        ]);

        foreach ([[$this->cheapItem->id => 7], [$this->cheapItem->id => 0], [999999 => 1]] as $items) {
            try {
                $submit($items);
                $this->fail('Return of '.json_encode($items).' was accepted');
            } catch (Exception) {
                $this->assertSame(0, ReturnRequest::count());
            }
        }

        $this->returnItems($parcel, [$this->cheapItem->id => 4]);

        $this->expectExceptionMessage('only 2 left');
        $submit([$this->cheapItem->id => 3]);
    }

    public function test_a_second_request_waits_until_the_first_is_decided(): void
    {
        $parcel = $this->deliveredParcel();
        $data = ['reason' => 'damaged_goods', 'requested_action' => 'refund', 'description' => 'Some pieces arrived cracked.'];
        $this->disputes->submitReturnRequest($parcel, $this->customer, $data + ['items' => [$this->cheapItem->id => 1]]);

        $this->expectExceptionMessage('already waiting');
        $this->disputes->submitReturnRequest($parcel->fresh(), $this->customer, $data + ['items' => [$this->dearItem->id => 1]]);
    }

    public function test_the_buyer_picks_items_on_the_tracking_page_and_the_seller_sees_them(): void
    {
        $parcel = $this->deliveredParcel();
        $this->actingAs($this->customer);

        Livewire::test(OrderTracking::class, ['orderNumber' => $parcel->order->order_number])
            ->call('openReturnModal', $parcel->id)
            ->assertSee($this->cheapItem->product->title)
            ->set("returnQuantities.{$this->cheapItem->id}", 2)
            ->set("returnQuantities.{$this->dearItem->id}", 0)
            ->set('returnDescription', 'Two pieces arrived cracked.')
            ->call('submitReturnRequest')
            ->assertHasNoErrors();

        $request = ReturnRequest::where('sub_order_id', $parcel->id)->firstOrFail();
        $this->assertSame([$this->cheapItem->id => 2], $request->items()->pluck('quantity', 'order_item_id')->map(fn ($q) => (int) $q)->all());

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        Livewire::test(ListReturnRequests::class)
            ->assertSee($this->cheapItem->product->title.' × 2');
    }

    public function test_a_courier_return_after_an_item_return_settles_only_what_was_left(): void
    {
        $parcel = $this->deliveredParcel();
        $this->returnItems($parcel, [$this->cheapItem->id => 3]);
        $stockBefore = (int) ProductVariation::whereKey($this->dearItem->variation_id)->value('stock_quantity');

        app(CourierStatusService::class)->updateStatus($parcel->fresh(), 'returned');

        $this->assertSame('returned', $parcel->fresh()->fulfillmentStatus->slug);
        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(1010.00, $this->ledger->getBalanceForSlug('customer_refund_payable'), 0.001);
        $this->assertEqualsWithDelta(0.00, app(EscrowLedgerService::class)->heldAmountFor($parcel), 0.001);
        $this->assertSame($stockBefore + 2, (int) ProductVariation::whereKey($this->dearItem->variation_id)->value('stock_quantity'));
        $this->assertSame(0, WalletTransaction::where('sub_order_id', $parcel->id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_REFUND)->count(), 'The whole-parcel refund must not run on top');
        $this->assertBooksBalance();
    }

    public function test_a_request_without_items_still_returns_the_whole_parcel(): void
    {
        $parcel = $this->deliveredParcel();
        $request = $this->disputes->submitReturnRequest($parcel, $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Everything arrived cracked.',
        ]);
        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');

        $this->assertSame('returned', $parcel->fresh()->fulfillmentStatus->slug);
        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->value('amount'), 0.001);
        $this->assertEqualsWithDelta(1010.00, $this->ledger->getBalanceForSlug('customer_refund_payable'), 0.001);
        $this->assertBooksBalance();
    }

    /**
     * Review R-1: the courier brings the whole parcel back while a return of some items is still waiting. The
     * parcel is refunded in full once; the waiting request is closed and can't be approved on top.
     */
    public function test_a_waiting_return_is_closed_when_the_courier_returns_the_whole_parcel(): void
    {
        $parcel = $this->deliveredParcel();
        $request = $this->disputes->submitReturnRequest($parcel->fresh(), $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Some pieces arrived cracked.',
            'items' => [$this->cheapItem->id => 3],
        ]);
        $stockAfterRto = null;

        app(CourierStatusService::class)->updateStatus($parcel->fresh(), 'returned');
        $stockAfterRto = $this->cheapItem->variation->fresh()->stock_quantity;
        $heldAfterRto = (float) $this->wallet()->escrow_held_balance;

        $this->assertSame(ReturnRequest::STATUS_RESOLVED, $request->fresh()->status);

        // Approving it anyway changes nothing
        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');

        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->sum('amount'), 0.001);
        $this->assertSame($stockAfterRto, $this->cheapItem->variation->fresh()->stock_quantity);
        $this->assertEqualsWithDelta($heldAfterRto, (float) $this->wallet()->escrow_held_balance, 0.001);
        $this->assertSame(0, $request->items()->whereNotNull('refund_amount')->count());
        $this->assertBooksBalance();
    }

    /**
     * Review R-1: a parcel no longer with the buyer can't have a refund return approved, even if a request was
     * left waiting (for example from before this rule).
     */
    public function test_a_refund_return_on_a_parcel_no_longer_delivered_is_refused(): void
    {
        $parcel = $this->deliveredParcel();
        $request = $this->disputes->submitReturnRequest($parcel->fresh(), $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Some pieces arrived cracked.',
            'items' => [$this->cheapItem->id => 3],
        ]);
        $parcel->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'returned')->value('id')]);

        try {
            $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');
            $this->fail('Approval should be refused.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('no longer with the buyer', $exception->getMessage());
        }

        $this->assertSame(ReturnRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(0, Refund::where('sub_order_id', $parcel->id)->count());
    }

    /**
     * Review R-4: a request with no items chosen, after earlier item returns, means the items still with the
     * buyer. It settles the rest instead of failing with an unbalanced journal.
     */
    public function test_a_request_for_everything_after_an_item_return_settles_the_rest(): void
    {
        $parcel = $this->deliveredParcel();
        $this->returnItems($parcel, [$this->cheapItem->id => 3]);

        $request = $this->disputes->submitReturnRequest($parcel->fresh(), $this->customer, [
            'reason' => 'damaged_goods',
            'requested_action' => 'refund',
            'description' => 'Everything else arrived cracked too.',
        ]);
        $this->disputes->resolveReturnRequest($request, ReturnRequest::STATUS_APPROVED, 'Accepted');

        $this->assertSame(ReturnRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame([$this->cheapItem->id => 3, $this->dearItem->id => 2], $request->items()->orderBy('order_item_id')->pluck('quantity', 'order_item_id')->map(fn ($quantity): int => (int) $quantity)->all());
        $this->assertSame('returned', $parcel->fresh()->fulfillmentStatus->slug);
        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->sum('amount'), 0.001);
        $this->assertBooksBalance();
    }

    /**
     * Review R-2: the seller collected the cash and the coupon (৳50) is bigger than the commission (৳10), so
     * delivery credited the seller ৳40. Once every item is back, item by item or all at once, that credit is
     * taken back and the wallet and the books agree.
     *
     * @return array<string, array{0: list<array<string, int>>}>
     */
    public static function couponBiggerThanCommissionReturns(): array
    {
        return [
            'item by item' => [[['cheap' => 3], ['cheap' => 3, 'dear' => 2]]],
            'all at once' => [[['cheap' => 6, 'dear' => 2]]],
        ];
    }

    /**
     * @param  list<array<string, int>>  $returns
     */
    #[DataProvider('couponBiggerThanCommissionReturns')]
    public function test_a_coupon_bigger_than_the_commission_is_taken_back_when_everything_returns(array $returns): void
    {
        $this->commission = 10.00;
        $walletBefore = (float) (SellerWallet::where('seller_id', $this->seller->id)->value('available_balance') ?? 0);
        $ledgerBefore = $this->ledger->getBalanceForSlug('seller_wallet_payable');
        $parcel = $this->deliveredParcel('cod', selfDelivered: true);
        $this->assertEqualsWithDelta($walletBefore + 40.00, (float) $this->wallet()->available_balance, 0.001);

        foreach ($returns as $return) {
            $this->returnItems($parcel, array_filter([
                $this->cheapItem->id => $return['cheap'] ?? 0,
                $this->dearItem->id => $return['dear'] ?? 0,
            ]));
        }

        // The ৳40 credit is taken back, and (item 8) the buyer's ৳1,010 refund is charged to the seller who kept the cash
        $this->assertEqualsWithDelta($walletBefore - 1010.00, (float) $this->wallet()->available_balance, 0.001);
        $this->assertEqualsWithDelta($ledgerBefore - 1010.00, $this->ledger->getBalanceForSlug('seller_wallet_payable'), 0.001);
        $this->assertEqualsWithDelta(1010.00, (float) Refund::where('sub_order_id', $parcel->id)->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->commissionLeft(), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->ledger->getBalanceForSlug('promotional_discount_expense'), 0.001);
        $this->assertBooksBalance();
    }
}
