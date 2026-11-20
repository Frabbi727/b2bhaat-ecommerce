<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\OrderItem;
use App\Models\ReturnRequestItem;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seller books: turns each B2Bhaat wallet movement into a balanced entry in the seller's own books, seen from the
 * seller's side. The wallet's two balances are mirrored by "B2Bhaat – money on hold" and "B2Bhaat – available
 * balance", so the books always agree with the wallet.
 *
 * Sales are booked per product (revenue, commission share, cost of goods) so profit can be read per product.
 * Commission is the balancing figure on sale and return entries, which keeps the mirror accounts exactly equal to
 * the wallet even when the platform's own rounding differs by a poisha.
 */
class SellerBooksProjector
{
    public function __construct(
        private readonly SellerJournalPostingService $posting,
    ) {}

    /**
     * Record one wallet transaction. Never throws: a failure is logged and the rebuild command fills the gap, so
     * the order flow is never blocked by the seller's books.
     */
    public function project(WalletTransaction $transaction): void
    {
        try {
            $this->record($transaction);
        } catch (\Throwable $e) {
            Log::error('Seller books: could not record wallet transaction', [
                'wallet_transaction_id' => $transaction->id,
                'type' => $transaction->transaction_type,
                'error' => $e->getMessage(),
            ]);

            if (app()->runningUnitTests()) {
                throw $e;
            }
        }
    }

    /**
     * A requested payout was paid out to the seller's bank or mobile wallet.
     */
    public function projectPayoutDisbursed(WalletTransaction $payout): void
    {
        try {
            $sellerId = $this->sellerIdFor($payout);
            $amount = (float) $payout->amount;

            // The request entry comes first, in case the payout pre-dates the seller books
            $this->record($payout);

            $draft = $this->draft($payout, SellerEntryType::PayoutReceived, 'Payout received from B2Bhaat', "payout_paid:{$payout->id}", $sellerId, $payout->updated_at);
            $draft->debit(Chart::BANK, $amount)->credit(Chart::PAYOUT_IN_TRANSIT, $amount);
            $this->posting->post($draft);
        } catch (\Throwable $e) {
            Log::error('Seller books: could not record payout disbursement', ['wallet_transaction_id' => $payout->id, 'error' => $e->getMessage()]);

            if (app()->runningUnitTests()) {
                throw $e;
            }
        }
    }

    /**
     * Wallet money with no wallet history behind it (set directly before the seller books existed) is taken into
     * the books as an opening balance, so the B2Bhaat accounts match the wallet. Only run on request.
     *
     * @param  array{on_hold_books: float, on_hold_wallet: float, available_books: float, available_wallet: float, in_transit_books: float, in_transit_wallet: float}  $check
     */
    public function adoptWalletDifference(int $sellerId, array $check): ?SellerJournalEntry
    {
        $onHold = round($check['on_hold_wallet'] - $check['on_hold_books'], 2);
        $available = round($check['available_wallet'] - $check['available_books'], 2);
        $inTransit = round($check['in_transit_wallet'] - $check['in_transit_books'], 2);

        $draft = new SellerEntryDraft(
            sellerId: $sellerId,
            type: SellerEntryType::OpeningBalance,
            description: 'B2Bhaat wallet balance brought into your books',
            entryDate: CarbonImmutable::now(config('app.display_timezone')),
            idempotencyKey: sprintf('wallet_opening:%d:%s', $sellerId, md5("{$onHold}|{$available}|{$inTransit}|".now()->toDateString())),
        );

        $draft->debit(Chart::B2BHAAT_ON_HOLD, $onHold)
            ->debit(Chart::B2BHAAT_AVAILABLE, $available)
            ->debit(Chart::PAYOUT_IN_TRANSIT, $inTransit)
            ->credit(Chart::OPENING_BALANCE, round($onHold + $available + $inTransit, 2));

        return $this->posting->post($draft);
    }

    private function record(WalletTransaction $tx): void
    {
        $sellerId = $this->sellerIdFor($tx);
        $amount = round((float) $tx->amount, 2);
        $subOrder = $tx->sub_order_id ? SubOrder::with(['items.product', 'items.variation', 'order.paymentMethod'])->find($tx->sub_order_id) : null;
        $ref = $subOrder ? "order #{$subOrder->sub_order_number}" : 'B2Bhaat';

        match ($tx->transaction_type) {
            WalletTransaction::TYPE_ESCROW_HOLD => $subOrder && $this->postSale($tx, $sellerId, $subOrder, $amount),

            WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT => $this->posting->post(
                $this->draft($tx, SellerEntryType::SaleAdjustment, "Delivery charge added for {$ref}", null, $sellerId)
                    ->debit(Chart::B2BHAAT_ON_HOLD, $amount)
                    ->credit(Chart::DELIVERY_INCOME, $amount)
            ),

            WalletTransaction::TYPE_ESCROW_RELEASE => $this->posting->post(
                $this->draft($tx, SellerEntryType::EscrowRelease, "Released to available balance for {$ref}", null, $sellerId)
                    ->debit(Chart::B2BHAAT_AVAILABLE, $amount)
                    ->credit(Chart::B2BHAAT_ON_HOLD, $amount)
            ),

            WalletTransaction::TYPE_ESCROW_REFUND => $subOrder && $this->postFullReturn($tx, $sellerId, $subOrder, $amount, Chart::B2BHAAT_ON_HOLD),
            WalletTransaction::TYPE_RETURN_REVERSAL => $subOrder && $this->postFullReturn($tx, $sellerId, $subOrder, $amount, Chart::B2BHAAT_AVAILABLE),

            WalletTransaction::TYPE_PARTIAL_RETURN_HELD => $subOrder && $this->postPartialReturn($tx, $sellerId, $subOrder, $amount, Chart::B2BHAAT_ON_HOLD),
            WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED => $subOrder && $this->postPartialReturn($tx, $sellerId, $subOrder, $amount, Chart::B2BHAAT_AVAILABLE),

            WalletTransaction::TYPE_COD_SELF_COLLECTED => $subOrder && $this->postSelfCollectedSale($tx, $sellerId, $subOrder, $amount),

            WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL => $subOrder && $this->postSelfCollectedReturn($tx, $sellerId, $subOrder, $amount),
            WalletTransaction::TYPE_PARTIAL_RETURN_COD => $subOrder && $this->postSelfCollectedReturn($tx, $sellerId, $subOrder, $amount),

            WalletTransaction::TYPE_COD_RETURN_REFUND => $subOrder && $this->postBuyerRefund($tx, $sellerId, $subOrder, $amount),

            WalletTransaction::TYPE_PAYOUT => $this->posting->post(
                $this->draft($tx, SellerEntryType::PayoutRequested, 'Payout requested from B2Bhaat', null, $sellerId)
                    ->debit(Chart::PAYOUT_IN_TRANSIT, $amount)
                    ->credit(Chart::B2BHAAT_AVAILABLE, $amount)
            ),

            WalletTransaction::TYPE_PAYOUT_REVERSAL => $this->posting->post(
                $this->draft($tx, SellerEntryType::PayoutReturned, 'Payout rejected; amount back in your balance', null, $sellerId)
                    ->debit(Chart::B2BHAAT_AVAILABLE, $amount)
                    ->credit(Chart::PAYOUT_IN_TRANSIT, $amount)
            ),

            WalletTransaction::TYPE_COD_DEFICIT_PAYMENT => $this->posting->post(
                $this->draft($tx, SellerEntryType::DuesPaid, 'Paid commission owed to B2Bhaat', null, $sellerId)
                    ->debit(Chart::B2BHAAT_AVAILABLE, $amount)
                    ->credit(Chart::BANK, $amount)
            ),

            'subscription_payment' => $this->posting->post(
                $this->draft($tx, SellerEntryType::PlatformFee, (string) ($tx->reference_note ?: 'B2Bhaat fee paid from balance'), null, $sellerId)
                    ->debit(Chart::PLATFORM_FEES, $amount)
                    ->credit(Chart::B2BHAAT_AVAILABLE, $amount)
            ),

            default => Log::warning('Seller books: unknown wallet transaction type skipped', ['wallet_transaction_id' => $tx->id, 'type' => $tx->transaction_type]),
        };
    }

    /**
     * Money held for a delivered or prepaid order: the sale, B2Bhaat's commission and the cost of the goods.
     */
    private function postSale(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $held): bool
    {
        $draft = $this->draft($tx, SellerEntryType::Sale, "Sale – order #{$subOrder->sub_order_number}", null, $sellerId, null, $subOrder->id);
        $delivery = $subOrder->is_self_delivered ? round((float) $subOrder->shipping_amount, 2) : 0.0;

        $draft->debit(Chart::B2BHAAT_ON_HOLD, $held);
        $this->addSaleLines($draft, $subOrder, $delivery, $held);
        $this->posting->post($draft);

        return true;
    }

    /**
     * A self-delivered COD order: the seller kept the buyer's cash, and B2Bhaat charges the commission (less the
     * coupon it pays for) to the available balance. A negative charge is a credit to the seller.
     */
    private function postSelfCollectedSale(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $owed): bool
    {
        $draft = $this->draft($tx, SellerEntryType::Sale, "Sale – order #{$subOrder->sub_order_number} (you collected the cash)", null, $sellerId, null, $subOrder->id);
        $cash = round($subOrder->codCollectAmount(), 2);
        $delivery = $subOrder->is_self_delivered ? round((float) $subOrder->shipping_amount, 2) : 0.0;

        $draft->debit(Chart::CASH, $cash)->credit(Chart::B2BHAAT_AVAILABLE, $owed);
        // Received = cash − amount owed to B2Bhaat; commission balances the rest
        $this->addSaleLines($draft, $subOrder, $delivery, round($cash - $owed, 2));
        $this->posting->post($draft);

        return true;
    }

    /**
     * Revenue per product, commission (the balancing figure, shared by product) and cost of goods sold.
     */
    private function addSaleLines(SellerEntryDraft $draft, SubOrder $subOrder, float $delivery, float $received): void
    {
        $items = $subOrder->items;
        $revenue = round((float) $items->sum(fn (OrderItem $item): float => (float) $item->total_price), 2);

        foreach ($items as $item) {
            $draft->credit(Chart::SALES, (float) $item->total_price, $item->product_id, $this->itemLabel($item));
        }

        $draft->credit(Chart::DELIVERY_INCOME, $delivery);

        $commission = round($revenue + $delivery - $received, 2);
        foreach ($this->shareByProduct($items->map(fn (OrderItem $item): array => ['product_id' => $item->product_id, 'weight' => (float) $item->total_price]), $commission) as $share) {
            $draft->debit(Chart::COMMISSION, $share['amount'], $share['product_id']);
        }

        foreach ($items as $item) {
            $cost = round($this->unitCost($item) * (int) $item->quantity, 2);
            $draft->debit(Chart::COGS, $cost, $item->product_id, $this->itemLabel($item));
            $draft->credit(Chart::INVENTORY, $cost, $item->product_id);
        }
    }

    /**
     * The whole order came back or was cancelled. Undo what is still booked for it (net of earlier partial
     * returns), put the goods back into stock, and take the money off the matching wallet account.
     */
    private function postFullReturn(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $amount, string $receivable): bool
    {
        $this->ensureSaleRecorded($subOrder);

        $draft = $this->draft($tx, SellerEntryType::SalesReturn, ($tx->transaction_type === WalletTransaction::TYPE_ESCROW_REFUND ? 'Order cancelled/returned' : 'Order returned')." – #{$subOrder->sub_order_number}", null, $sellerId, null, $subOrder->id);
        $net = $this->bookedForSubOrder($sellerId, $subOrder->id);

        $this->addReturnOfBookedLines($draft, $net);
        $draft->credit($receivable, $amount);

        $revenue = round($net['revenue']->sum(), 2);
        $commission = round($revenue + $net['delivery'] - $amount, 2);
        foreach ($this->shareByProduct($net['revenue']->map(fn (float $value, int|string $productId): array => ['product_id' => $productId === '' ? null : (int) $productId, 'weight' => $value])->values(), $commission) as $share) {
            $draft->credit(Chart::COMMISSION, $share['amount'], $share['product_id']);
        }

        $this->posting->post($draft);

        return true;
    }

    /**
     * Some items came back (B2Bhaat collected or held the money). The wallet amount is the goods less their
     * commission, plus the delivery charge when the last items come back from a self-delivered parcel.
     */
    private function postPartialReturn(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $amount, string $receivable): bool
    {
        $this->ensureSaleRecorded($subOrder);

        $lines = $this->returnLines($tx);
        $draft = $this->draft($tx, SellerEntryType::SalesReturn, "Items returned – order #{$subOrder->sub_order_number}", null, $sellerId, null, $subOrder->id);

        $sellerShare = round((float) $lines->sum(fn (ReturnRequestItem $line): float => (float) $line->seller_amount), 2);
        $delivery = $subOrder->is_self_delivered ? max(0.0, round($amount - $sellerShare, 2)) : 0.0;

        $goods = $this->addReturnedGoodsLines($draft, $lines);
        $draft->debit(Chart::DELIVERY_INCOME, $delivery);
        $draft->credit($receivable, $amount);

        $commission = round($goods->sum() + $delivery - $amount, 2);
        foreach ($this->shareByProduct($goods->map(fn (float $value, int|string $productId): array => ['product_id' => $productId === '' ? null : (int) $productId, 'weight' => $value])->values(), $commission) as $share) {
            $draft->credit(Chart::COMMISSION, $share['amount'], $share['product_id']);
        }

        $this->posting->post($draft);

        return true;
    }

    /**
     * Self-collected COD return (whole order or some items). The goods come back and the seller now owes the buyer
     * a refund; B2Bhaat gives back the commission share it charged.
     */
    private function postSelfCollectedReturn(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $amount): bool
    {
        $this->ensureReturnedGoodsRecorded($tx, $sellerId, $subOrder);

        $this->posting->post(
            $this->draft($tx, SellerEntryType::SalesReturn, "Commission given back for returned order #{$subOrder->sub_order_number}", null, $sellerId, null, $subOrder->id)
                ->debit(Chart::B2BHAAT_AVAILABLE, $amount)
                ->credit(Chart::REFUNDS_OWED, $amount)
        );

        return true;
    }

    /**
     * B2Bhaat refunded the buyer for a self-collected COD return and took it from the seller's balance.
     */
    private function postBuyerRefund(WalletTransaction $tx, int $sellerId, SubOrder $subOrder, float $amount): bool
    {
        $this->ensureReturnedGoodsRecorded($tx, $sellerId, $subOrder);

        $this->posting->post(
            $this->draft($tx, SellerEntryType::BuyerRefund, "Buyer refunded for returned order #{$subOrder->sub_order_number}", null, $sellerId, null, $subOrder->id)
                ->debit(Chart::REFUNDS_OWED, $amount)
                ->credit(Chart::B2BHAAT_AVAILABLE, $amount)
        );

        return true;
    }

    /**
     * Book the returned goods of a self-collected COD order once per return (or once for a whole-order return):
     * revenue and commission come off, stock goes back, and what the buyer is owed goes to "Refunds owed".
     */
    private function ensureReturnedGoodsRecorded(WalletTransaction $tx, int $sellerId, SubOrder $subOrder): void
    {
        $this->ensureSaleRecorded($subOrder);

        if ($tx->return_request_id) {
            $key = "return_goods:rr:{$tx->return_request_id}";
            $lines = $this->returnLines($tx);
            $draft = $this->draft($tx, SellerEntryType::SalesReturn, "Items returned – order #{$subOrder->sub_order_number}", $key, $sellerId, null, $subOrder->id);
            $goods = $this->addReturnedGoodsLines($draft, $lines);

            $commission = round((float) $lines->sum(fn (ReturnRequestItem $line): float => (float) $line->commission_amount), 2);
            foreach ($this->shareByProduct($goods->map(fn (float $value, int|string $productId): array => ['product_id' => $productId === '' ? null : (int) $productId, 'weight' => $value])->values(), $commission) as $share) {
                $draft->credit(Chart::COMMISSION, $share['amount'], $share['product_id']);
            }

            $delivery = ($subOrder->is_self_delivered && $this->returnCompletesSubOrder($subOrder, (int) $tx->return_request_id))
                ? round((float) $subOrder->shipping_amount, 2) : 0.0;
            $draft->debit(Chart::DELIVERY_INCOME, $delivery);
            $draft->credit(Chart::REFUNDS_OWED, round($goods->sum() + $delivery - $commission, 2));

            $this->posting->post($draft);

            return;
        }

        $key = "return_goods:sub:{$subOrder->id}";
        $draft = $this->draft($tx, SellerEntryType::SalesReturn, "Order returned – #{$subOrder->sub_order_number}", $key, $sellerId, null, $subOrder->id);
        if (SellerJournalEntry::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $net = $this->bookedForSubOrder($sellerId, $subOrder->id);
        $this->addReturnOfBookedLines($draft, $net);
        foreach ($net['commission'] as $productId => $value) {
            $draft->credit(Chart::COMMISSION, $value, $productId === '' ? null : (int) $productId);
        }
        $draft->credit(Chart::REFUNDS_OWED, round($net['revenue']->sum() + $net['delivery'] - $net['commission']->sum(), 2));

        $this->posting->post($draft);
    }

    /**
     * Revenue back as sales returns, delivery income back, cost of goods back into stock.
     *
     * @param  array{revenue: Collection<int|string, float>, commission: Collection<int|string, float>, cogs: Collection<int|string, float>, delivery: float}  $net
     */
    private function addReturnOfBookedLines(SellerEntryDraft $draft, array $net): void
    {
        foreach ($net['revenue'] as $productId => $value) {
            $draft->debit(Chart::SALES_RETURNS, $value, $productId === '' ? null : (int) $productId);
        }

        $draft->debit(Chart::DELIVERY_INCOME, $net['delivery']);

        foreach ($net['cogs'] as $productId => $value) {
            $product = $productId === '' ? null : (int) $productId;
            $draft->debit(Chart::INVENTORY, $value, $product, 'Back into stock');
            $draft->credit(Chart::COGS, $value, $product);
        }
    }

    /**
     * Sales returns per returned item and the cost put back into stock.
     *
     * @param  Collection<int, ReturnRequestItem>  $lines
     * @return Collection<int|string, float> goods value by product id
     */
    private function addReturnedGoodsLines(SellerEntryDraft $draft, Collection $lines): Collection
    {
        $goods = collect();

        foreach ($lines as $line) {
            $item = $line->orderItem;
            $productId = $item?->product_id;
            $value = round((float) $line->seller_amount + (float) $line->commission_amount, 2);

            $draft->debit(Chart::SALES_RETURNS, $value, $productId, $item ? $this->itemLabel($item, (int) $line->quantity) : null);
            $goods[(string) $productId] = round(($goods[(string) $productId] ?? 0.0) + $value, 2);

            if ($item) {
                $cost = round($this->unitCost($item) * (int) $line->quantity, 2);
                $draft->debit(Chart::INVENTORY, $cost, $productId, 'Back into stock');
                $draft->credit(Chart::COGS, $cost, $productId);
            }
        }

        return $goods;
    }

    /**
     * What the seller's books still hold for one order, per product: revenue net of returns, commission and cost of
     * goods, plus delivery income.
     *
     * @return array{revenue: Collection<int|string, float>, commission: Collection<int|string, float>, cogs: Collection<int|string, float>, delivery: float}
     */
    private function bookedForSubOrder(int $sellerId, int $subOrderId): array
    {
        $rows = DB::table('seller_journal_lines as l')
            ->join('seller_accounts as a', 'a.id', '=', 'l.seller_account_id')
            ->where('l.seller_id', $sellerId)
            ->where('l.sub_order_id', $subOrderId)
            ->whereIn('a.slug', [Chart::SALES, Chart::SALES_RETURNS, Chart::COMMISSION, Chart::COGS, Chart::DELIVERY_INCOME])
            ->groupBy('a.slug', 'l.product_id')
            ->selectRaw('a.slug, l.product_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        $revenue = collect();
        $commission = collect();
        $cogs = collect();
        $delivery = 0.0;

        foreach ($rows as $row) {
            $key = (string) $row->product_id;
            $creditBalance = round((float) $row->credit - (float) $row->debit, 2);

            match ($row->slug) {
                Chart::SALES, Chart::SALES_RETURNS => $revenue[$key] = round(($revenue[$key] ?? 0.0) + $creditBalance, 2),
                Chart::COMMISSION => $commission[$key] = round(($commission[$key] ?? 0.0) - $creditBalance, 2),
                Chart::COGS => $cogs[$key] = round(($cogs[$key] ?? 0.0) - $creditBalance, 2),
                Chart::DELIVERY_INCOME => $delivery = round($delivery + $creditBalance, 2),
                default => null,
            };
        }

        $positive = fn (Collection $values): Collection => $values->filter(fn (float $value): bool => $value != 0.0);

        return ['revenue' => $positive($revenue), 'commission' => $positive($commission), 'cogs' => $positive($cogs), 'delivery' => $delivery];
    }

    /**
     * A return can only be booked against a recorded sale; record the sale first if it is missing (an order from
     * before the seller books existed).
     */
    private function ensureSaleRecorded(SubOrder $subOrder): void
    {
        $sale = WalletTransaction::query()
            ->where('sub_order_id', $subOrder->id)
            ->whereIn('transaction_type', [WalletTransaction::TYPE_ESCROW_HOLD, WalletTransaction::TYPE_COD_SELF_COLLECTED])
            ->orderBy('id')
            ->get();

        foreach ($sale as $transaction) {
            if (! SellerJournalEntry::query()->where('idempotency_key', "wallet_tx:{$transaction->id}")->exists()) {
                $this->record($transaction);
            }
        }
    }

    /**
     * @return Collection<int, ReturnRequestItem>
     */
    private function returnLines(WalletTransaction $tx): Collection
    {
        if (! $tx->return_request_id) {
            return collect();
        }

        return ReturnRequestItem::query()
            ->with(['orderItem.product', 'orderItem.variation'])
            ->where('return_request_id', $tx->return_request_id)
            ->get();
    }

    /**
     * Whether this return, together with the returns already settled, brings back every item of the order.
     */
    private function returnCompletesSubOrder(SubOrder $subOrder, int $returnRequestId): bool
    {
        $settledReturnIds = WalletTransaction::query()
            ->where('sub_order_id', $subOrder->id)
            ->whereNotNull('return_request_id')
            ->pluck('return_request_id')
            ->push($returnRequestId)
            ->unique();

        $returned = (int) ReturnRequestItem::query()->whereIn('return_request_id', $settledReturnIds)->sum('quantity');
        $ordered = (int) $subOrder->items->sum('quantity');

        return $ordered > 0 && $returned >= $ordered;
    }

    /**
     * Split an amount across products in proportion to their weights; the last product takes the rounding.
     *
     * @param  Collection<int, array{product_id: ?int, weight: float}>  $parts
     * @return list<array{product_id: ?int, amount: float}>
     */
    private function shareByProduct(Collection $parts, float $amount): array
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            return [];
        }

        $weights = [];
        foreach ($parts as $part) {
            $key = (string) ($part['product_id'] ?? '');
            $weights[$key] = ($weights[$key] ?? 0.0) + $part['weight'];
        }

        $total = array_sum($weights);
        if ($weights === [] || $total == 0.0) {
            return [['product_id' => null, 'amount' => $amount]];
        }

        $shares = [];
        $allocated = 0.0;
        $remaining = count($weights);
        foreach ($weights as $key => $weight) {
            $remaining--;
            $share = $remaining === 0 ? round($amount - $allocated, 2) : round($amount * $weight / $total, 2);
            $allocated = round($allocated + $share, 2);
            $shares[] = ['product_id' => (string) $key === '' ? null : (int) $key, 'amount' => $share];
        }

        return $shares;
    }

    /**
     * The seller's cost per unit: the one copied onto the order line, otherwise today's cost on the option or
     * product (orders placed before costs were copied).
     */
    private function unitCost(OrderItem $item): float
    {
        if ($item->unit_cost !== null) {
            return (float) $item->unit_cost;
        }

        $variationCost = (float) ($item->variation?->buy_price ?? 0);

        return $variationCost > 0 ? $variationCost : (float) ($item->product?->buy_price ?? 0);
    }

    private function itemLabel(OrderItem $item, ?int $quantity = null): string
    {
        $title = $item->product?->title ?? "Product #{$item->product_id}";

        return mb_substr(($quantity ?? (int) $item->quantity).' × '.$title, 0, 255);
    }

    private function sellerIdFor(WalletTransaction $tx): int
    {
        return (int) ($tx->wallet?->seller_id ?? DB::table('seller_wallets')->where('id', $tx->seller_wallet_id)->value('seller_id'));
    }

    private function draft(WalletTransaction $tx, SellerEntryType $type, string $description, ?string $key, int $sellerId, mixed $date = null, ?int $subOrderId = null): SellerEntryDraft
    {
        $when = CarbonImmutable::parse($date ?? $tx->created_at ?? now())->setTimezone(config('app.display_timezone'));

        return new SellerEntryDraft(
            sellerId: $sellerId,
            type: $type,
            description: $description,
            entryDate: $when,
            idempotencyKey: $key ?? "wallet_tx:{$tx->id}",
            walletTransactionId: $tx->id,
            subOrderId: $subOrderId ?? $tx->sub_order_id,
        );
    }
}
