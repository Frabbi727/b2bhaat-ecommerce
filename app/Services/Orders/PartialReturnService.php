<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTrackingLog;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\SubOrder;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use App\Services\Promotions\FlashSaleAllocationService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * PAY-4: returns of some items of a delivered parcel. Owner rules (2026-11-10):
 *  - the buyer gets back the items' price minus their share of the coupon;
 *  - the commission on those items is given back in proportion, so the seller loses price − commission share;
 *  - the delivery fee is refunded only when the whole parcel has come back.
 *
 * The return that brings back the last items settles whatever is left, so rounding never leaves a few poisha behind.
 */
class PartialReturnService
{
    public function __construct(
        protected EscrowLedgerService $escrowLedgerService,
        protected StockReservationService $stockReservationService,
        protected OrderStatusReconciler $statusReconciler,
    ) {}

    /**
     * Units of each item still with the buyer: ordered less what approved refund returns already took back.
     *
     * @return array<int, int> order item id => units
     */
    public function remainingQuantities(SubOrder $subOrder, ?int $exceptRequestId = null): array
    {
        $returned = $this->approvedReturnLines($subOrder, $exceptRequestId)
            ->groupBy('order_item_id')
            ->map(fn (Collection $lines): int => (int) $lines->sum('quantity'));

        return $subOrder->items()->orderBy('id')->get(['id', 'quantity'])
            ->mapWithKeys(fn (OrderItem $item): array => [$item->id => max(0, (int) $item->quantity - (int) ($returned[$item->id] ?? 0))])
            ->all();
    }

    /**
     * Checks a buyer's choice of items and drops the ones they left at zero.
     *
     * @param  array<int|string, int|string|null>  $requested  order item id => units
     * @return array<int, int>
     *
     * @throws InvalidArgumentException
     */
    public function validatedQuantities(SubOrder $subOrder, array $requested): array
    {
        $remaining = $this->remainingQuantities($subOrder);
        $names = $subOrder->items()->with('product:id,title')->get()->mapWithKeys(fn (OrderItem $item): array => [$item->id => $item->product?->title ?? 'This item']);
        $chosen = [];

        foreach ($requested as $itemId => $quantity) {
            $itemId = (int) $itemId;
            $quantity = (int) $quantity;

            if (! array_key_exists($itemId, $remaining)) {
                throw new InvalidArgumentException('One of the chosen items is not in this parcel.');
            }

            if ($quantity < 0) {
                throw new InvalidArgumentException('Item quantities cannot be negative.');
            }

            if ($quantity > $remaining[$itemId]) {
                throw new InvalidArgumentException("{$names[$itemId]}: you can return only {$remaining[$itemId]} left in this parcel, not {$quantity}. Units already returned do not count.");
            }

            if ($quantity > 0) {
                $chosen[$itemId] = $quantity;
            }
        }

        if ($chosen === []) {
            throw new InvalidArgumentException('Choose at least one item to return.');
        }

        return $chosen;
    }

    /**
     * True when this approved refund must be settled item by item: it covers only part of the parcel, or earlier
     * returns already took some items back. A return of everything at once keeps the whole-parcel path.
     */
    public function settlesItemByItem(ReturnRequest $returnRequest): bool
    {
        $lines = $returnRequest->items()->get();
        if ($lines->isEmpty()) {
            return false;
        }

        $subOrder = $returnRequest->subOrder;
        if ($this->approvedReturnLines($subOrder, $returnRequest->id)->isNotEmpty()) {
            return true;
        }

        $chosen = $lines->pluck('quantity', 'order_item_id')->map(fn ($quantity): int => (int) $quantity)->all();

        return array_filter($this->remainingQuantities($subOrder, $returnRequest->id), fn (int $units, int $itemId): bool => ($chosen[$itemId] ?? 0) < $units, ARRAY_FILTER_USE_BOTH) !== [];
    }

    /**
     * Whether the parcel already had items returned one return at a time.
     */
    public function hasItemReturns(SubOrder $subOrder): bool
    {
        return $this->approvedReturnLines($subOrder)->isNotEmpty();
    }

    /**
     * Settle an approved refund return item by item: store each line's money, move the seller's money, record what
     * the buyer is owed, restock, and mark the parcel returned once nothing is left with the buyer.
     */
    public function settle(ReturnRequest $returnRequest, string $reason): void
    {
        $subOrder = $returnRequest->subOrder()->with('order.paymentStatus', 'items')->firstOrFail();
        $lines = $returnRequest->items()->with('orderItem')->orderBy('id')->get();
        $remaining = $this->remainingQuantities($subOrder, $returnRequest->id);

        $completes = collect($remaining)->every(fn (int $units, int $itemId): bool => $units <= (int) ($lines->firstWhere('order_item_id', $itemId)?->quantity ?? 0));

        $amounts = $this->priceLines($subOrder, $returnRequest, $lines, $completes);

        $this->escrowLedgerService->reversePartialReturn($subOrder, $returnRequest, $amounts, $reason);

        if ($subOrder->order?->paymentStatus?->slug === 'paid' && $amounts['refund'] > 0) {
            Refund::create([
                'order_id' => $subOrder->parent_order_id,
                'sub_order_id' => $subOrder->id,
                'return_request_id' => $returnRequest->id,
                'amount' => $amounts['refund'],
                'reason' => mb_substr($reason, 0, 255),
                'status' => Refund::STATUS_PENDING,
            ]);
        }

        $this->restock($lines);

        $returnedStatus = $completes ? FulfillmentStatus::where('slug', 'returned')->first() : null;
        if ($returnedStatus) {
            $subOrder->update(['fulfillment_status_id' => $returnedStatus->id]);
        }

        OrderTrackingLog::create([
            'sub_order_id' => $subOrder->id,
            'fulfillment_status_id' => $returnedStatus->id ?? $subOrder->fulfillment_status_id,
            'title' => $completes ? 'Return Approved by Supplier' : 'Part of the parcel returned',
            'description' => 'Returned: '.$returnRequest->itemsSummary().'. Refund ৳'.number_format($amounts['refund'], 2).'. '.$reason,
            'hub_location' => 'Merchant Hub',
            'logged_at' => now(),
        ]);

        if ($completes && $subOrder->order instanceof Order) {
            $this->statusReconciler->reconcile($subOrder->order);
        }
    }

    /**
     * Courier brought back a parcel that already had items returned: settle just what was still with the buyer.
     */
    public function returnRemainder(SubOrder $subOrder, string $reason): ?ReturnRequest
    {
        $left = array_filter($this->remainingQuantities($subOrder));
        if ($left === []) {
            return null;
        }

        $returnRequest = ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $subOrder->order->customer_id,
            'reason' => 'returned_by_courier',
            'requested_action' => 'refund',
            'description' => $reason,
            'status' => ReturnRequest::STATUS_APPROVED,
            'resolved_at' => now(),
        ]);

        foreach ($left as $itemId => $units) {
            $returnRequest->items()->create(['order_item_id' => $itemId, 'quantity' => $units]);
        }

        $this->settle($returnRequest, $reason);

        return $returnRequest;
    }

    /**
     * @param  Collection<int, ReturnRequestItem>  $lines
     * @return array{seller: float, commission: float, discount: float, refund: float, courier_fee: float, completes: bool}
     */
    private function priceLines(SubOrder $subOrder, ReturnRequest $returnRequest, Collection $lines, bool $completes): array
    {
        $subtotal = (float) $subOrder->subtotal;
        $commission = (float) $subOrder->commission_amount;
        $discount = $subOrder->allocatedDiscount();

        $priced = $lines->map(function (ReturnRequestItem $line) use ($subtotal, $commission, $discount): array {
            $goods = round((float) $line->orderItem->unit_price * (int) $line->quantity, 2);
            $share = $subtotal > 0 ? $goods / $subtotal : 0.0;

            return ['line' => $line, 'goods' => $goods, 'commission' => round($commission * $share, 2), 'discount' => round($discount * $share, 2)];
        })->values()->all();

        if ($completes && $priced !== []) {
            // The last return takes whatever is left, so all returns add up to the parcel exactly
            $earlier = $this->approvedReturnLines($subOrder, $returnRequest->id);
            $earlierGoods = (float) $earlier->sum(fn (ReturnRequestItem $line): float => (float) $line->seller_amount + (float) $line->commission_amount);
            $last = array_key_last($priced);

            foreach ([
                'goods' => $subtotal - $earlierGoods,
                'commission' => $commission - (float) $earlier->sum('commission_amount'),
                'discount' => $discount - (float) $earlier->sum('discount_share'),
            ] as $key => $whatIsLeft) {
                $priced[$last][$key] = round($priced[$last][$key] + round($whatIsLeft, 2) - array_sum(array_column($priced, $key)), 2);
            }
        }

        $totals = ['seller' => 0.0, 'commission' => 0.0, 'discount' => 0.0, 'refund' => 0.0];
        foreach ($priced as $row) {
            $seller = round($row['goods'] - $row['commission'], 2);
            $refund = round($row['goods'] - $row['discount'], 2);

            $row['line']->update([
                'refund_amount' => $refund,
                'commission_amount' => $row['commission'],
                'seller_amount' => $seller,
                'discount_share' => $row['discount'],
            ]);

            $totals['seller'] += $seller;
            $totals['commission'] += $row['commission'];
            $totals['discount'] += $row['discount'];
            $totals['refund'] += $refund;
        }

        // The delivery fee comes back only with the whole parcel; a seller who delivered it gives up the fee too
        $fee = $completes ? round((float) $subOrder->shipping_amount, 2) : 0.0;

        return [
            'seller' => round($totals['seller'] + ($subOrder->is_self_delivered ? $fee : 0.0), 2),
            'commission' => round($totals['commission'], 2),
            'discount' => round($totals['discount'], 2),
            'refund' => round($totals['refund'] + $fee, 2),
            'courier_fee' => $subOrder->is_self_delivered ? 0.0 : $fee,
            'completes' => $completes,
        ];
    }

    /**
     * @param  Collection<int, ReturnRequestItem>  $lines
     */
    private function restock(Collection $lines): void
    {
        $stock = [];
        $returnedItems = [];

        foreach ($lines as $line) {
            $item = $line->orderItem;
            if ($item->variation_id) {
                $stock[] = ['variation_id' => $item->variation_id, 'quantity' => (int) $line->quantity];
            }

            // A copy with the returned units, so flash-sale counts drop by what came back only
            $returned = $item->replicate();
            $returned->quantity = (int) $line->quantity;
            $returnedItems[] = $returned;
        }

        // Offers before stock rows, as checkout locks them (review R-5)
        app(FlashSaleAllocationService::class)->release($returnedItems);

        if ($stock !== []) {
            $this->stockReservationService->releaseStock($stock);
        }
    }

    /**
     * @return Collection<int, ReturnRequestItem>
     */
    private function approvedReturnLines(SubOrder $subOrder, ?int $exceptRequestId = null): Collection
    {
        return ReturnRequestItem::query()
            ->whereHas('returnRequest', fn ($query) => $query
                ->where('sub_order_id', $subOrder->id)
                ->where('status', ReturnRequest::STATUS_APPROVED)
                ->where('requested_action', 'refund')
                ->when($exceptRequestId, fn ($query) => $query->whereKeyNot($exceptRequestId)))
            ->get();
    }
}
