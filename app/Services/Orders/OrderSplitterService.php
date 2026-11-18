<?php

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Formatting\Money;
use App\Services\Inventory\StockReservationService;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Promotions\FlashSaleAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderSplitterService
{
    public function __construct(
        protected StockReservationService $stockReservationService,
        protected ShippingCalculatorService $shippingCalculatorService,
        protected FlashSaleAllocationService $flashSaleAllocationService,
        protected MonetizationEngineService $monetizationEngine,
    ) {}

    /**
     * Create master Order and automatically partition into vendor Sub-Orders.
     *
     * @param array{
     *     payment_method_id: int,
     *     shipping_address: array<string, mixed>,
     *     items: array<int, array{
     *         product_id: int,
     *         variation_id: ?int,
     *         sales_channel_id: int,
     *         quantity: int,
     *         unit_price: float,
     *         flash_sale_product_id?: ?int,
     *         original_unit_price?: ?float
     *     }>
     * } $data
     */
    /**
     * @param  array<int, array{product_id: int, variation_id: ?int}>  $items
     *
     * @throws \DomainException
     */
    private function assertOptionsStillSold(array $items): void
    {
        // WA-PRC-03: a product with options must be ordered by option, or the seller can't ship it and no stock is taken
        $productsWithOptions = Product::query()
            ->whereIn('id', collect($items)->whereNull('variation_id')->pluck('product_id')->filter()->unique())
            ->where('has_variations', true)
            ->pluck('title');
        if ($productsWithOptions->isNotEmpty()) {
            throw new \DomainException("Please choose an option (colour, size…) for {$productsWithOptions->first()}. Remove it from your cart and add it again with an option.");
        }

        $variationIds = collect($items)->pluck('variation_id')->filter()->unique()->values();
        if ($variationIds->isEmpty()) {
            return;
        }

        $variations = ProductVariation::with('product')->whereIn('id', $variationIds)->get()->keyBy('id');

        foreach ($items as $item) {
            if (empty($item['variation_id'])) {
                continue;
            }

            $variation = $variations->get((int) $item['variation_id']);

            if (! $variation || (int) $variation->product_id !== (int) $item['product_id']) {
                throw new \DomainException('An option in your cart is no longer sold. Remove it from your cart to continue.');
            }

            if ($variation->product?->has_variations && ! $variation->is_active) {
                throw new \DomainException("Option {$variation->sku} is no longer sold. Remove it from your cart to continue.");
            }
        }
    }

    public function createAndSplitOrder(User $customer, array $data): Order
    {
        // WA-CHK-02: a line with no units or a negative price would lower the order total
        foreach ($data['items'] as $item) {
            if ((int) $item['quantity'] < 1 || (float) $item['unit_price'] < 0) {
                throw new \DomainException('An item in your cart has an invalid quantity or price. Remove it from your cart to continue.');
            }
        }

        return DB::transaction(function () use ($customer, $data) {
            // Only approved, live products may be ordered, even if the cart was filled earlier
            $orderedProductIds = collect($data['items'])->pluck('product_id')->unique()->filter()->values();
            if (Product::query()->sellable()->whereIn('id', $orderedProductIds)->count() !== $orderedProductIds->count()) {
                throw new \DomainException('Some items in your cart are no longer available.');
            }

            // Flash-priced lines are checked and their offers locked before anything is written
            $this->flashSaleAllocationService->claim($customer, $data['items']);

            // 0. Verify no participating vendor is currently on vacation mode
            $productIds = collect($data['items'])->pluck('product_id')->unique()->filter();
            $vacationProducts = Product::with('seller.sellerProfile')
                ->whereIn('id', $productIds)
                ->get()
                ->filter(fn ($p) => (bool) $p->seller?->sellerProfile?->is_on_vacation);

            if ($vacationProducts->isNotEmpty()) {
                $storeName = $vacationProducts->first()->seller?->sellerProfile?->store_name ?? 'A merchant';
                throw new \Exception("Cannot place order: {$storeName} is currently on vacation mode.");
            }

            // INV-5: every option must still belong to its product and be on sale, even if the cart was filled earlier
            $this->assertOptionsStillSold($data['items']);

            // WA-PRC-02: wholesale prices only for a buyer who still has wholesale access
            $this->assertWholesaleAccess($customer, $data['items']);

            // Quoted lines must match their accepted, unused quotation; the rows stay locked until the order commits
            $quotedRfqIds = $this->lockQuotedRfqs($customer, $data['items']);

            // 1. Reserve stock atomically
            $reservationItems = [];
            foreach ($data['items'] as $item) {
                if (! empty($item['variation_id'])) {
                    $reservationItems[] = [
                        'variation_id' => $item['variation_id'],
                        'quantity' => $item['quantity'],
                    ];
                }
            }
            if (! empty($reservationItems)) {
                $this->stockReservationService->reserveStock($reservationItems);
            }

            // 2. Fetch default initial statuses
            $pendingStatus = OrderStatus::where('slug', 'pending')->firstOrFail();
            $unpaidStatus = PaymentStatus::where('slug', 'unpaid')->firstOrFail();
            $unfulfilledStatus = FulfillmentStatus::where('slug', 'unfulfilled')->firstOrFail();

            // Calculate total amount & shipping fee breakdown (Decision D6)
            $itemsTotal = Money::lineTotal($data['items']);

            $hasExplicitZone = isset($data['shipping_address']['delivery_zone']);
            $hasExplicitShipping = isset($data['shipping_amount']);

            $zone = $data['shipping_address']['delivery_zone'] ?? 'inside_dhaka';
            $shippingResult = ($hasExplicitZone || $hasExplicitShipping)
                ? $this->shippingCalculatorService->calculateShipping($data['items'], $zone)
                : ['total_shipping' => 0.0, 'vendors' => []];

            // WA-MNY-03: the order's shipping is always the sum of its parcels' fees, worked out here
            $shippingAmount = round((float) $shippingResult['total_shipping'], 2);
            if ($hasExplicitShipping && Money::poisha($data['shipping_amount']) !== Money::poisha($shippingAmount)) {
                throw new \DomainException('Delivery charges changed while you were checking out. Please check the delivery charge and place the order again.');
            }

            $discountAmount = isset($data['discount_amount'])
                ? (float) $data['discount_amount']
                : 0.00;

            $grandTotal = max(0.00, round($itemsTotal + $shippingAmount - $discountAmount, 2));

            $orderNumber = 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(6));

            // 3. Create master Order
            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customer->id,
                'order_status_id' => $pendingStatus->id,
                'payment_status_id' => $unpaidStatus->id,
                'payment_method_id' => $data['payment_method_id'],
                'coupon_id' => $data['coupon_id'] ?? null,
                'coupon_code' => $data['coupon_code'] ?? null,
                'shipping_address' => $data['shipping_address'],
                'total_amount' => $grandTotal,
                'tax_amount' => 0.00,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $discountAmount,
            ]);

            // 4. Group order items by seller
            $itemsBySeller = [];
            $productsCache = [];

            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                if (! isset($productsCache[$productId])) {
                    $productsCache[$productId] = Product::with('seller.sellerProfile', 'salesChannel')->findOrFail($productId);
                }
                $product = $productsCache[$productId];
                $sellerId = $product->seller_id;

                $itemsBySeller[$sellerId][] = [
                    'product' => $product,
                    'item_data' => $item,
                ];
            }

            // 5. Generate sub-orders for each seller
            $suffixIndex = 0;
            $alphabet = range('A', 'Z');

            foreach ($itemsBySeller as $sellerId => $sellerItemList) {
                $subOrderNumber = $orderNumber.'-'.($alphabet[$suffixIndex % 26]);
                $suffixIndex++;

                $subtotalPoisha = 0;
                $commissionPoisha = 0.0;

                // Calculate subtotal and commission
                $seller = $sellerItemList[0]['product']->seller;

                foreach ($sellerItemList as $index => $entry) {
                    $product = $entry['product'];
                    $itemData = $entry['item_data'];
                    $linePoisha = Money::poisha($itemData['unit_price']) * (int) $itemData['quantity'];
                    $subtotalPoisha += $linePoisha;

                    // Dynamically resolve commission rate through MonetizationEngine
                    $channel = SalesChannel::find($itemData['sales_channel_id'])
                        ?? $product->salesChannel;
                    $rate = $channel
                        ? $this->monetizationEngine->resolveCommissionRate($seller, $product, $channel)
                        : $this->monetizationEngine->sellerCommissionRate($seller, wholesale: false);
                    $sellerItemList[$index]['commission_rate'] = $rate;

                    $commissionPoisha += $linePoisha * ($rate / 100.0);
                }

                $vendorShipping = $shippingResult['vendors'][$sellerId]['shipping_fee'] ?? 0.00;

                $subOrder = SubOrder::create([
                    'parent_order_id' => $order->id,
                    'seller_id' => $sellerId,
                    'sub_order_number' => $subOrderNumber,
                    'fulfillment_status_id' => $unfulfilledStatus->id,
                    'subtotal' => Money::fromPoisha($subtotalPoisha),
                    'commission_amount' => Money::fromPoisha((int) round($commissionPoisha)),
                    'shipping_amount' => round($vendorShipping, 2),
                ]);

                foreach ($sellerItemList as $entry) {
                    $itemData = $entry['item_data'];
                    OrderItem::create([
                        'sub_order_id' => $subOrder->id,
                        'product_id' => $itemData['product_id'],
                        'variation_id' => $itemData['variation_id'] ?? null,
                        'sales_channel_id' => $itemData['sales_channel_id'],
                        'unit_price' => $itemData['unit_price'],
                        'unit_cost' => $this->unitCostFor($entry['product'], $itemData['variation_id'] ?? null),
                        'flash_sale_product_id' => $itemData['flash_sale_product_id'] ?? null,
                        'original_unit_price' => $itemData['original_unit_price'] ?? null,
                        'quantity' => $itemData['quantity'],
                        'total_price' => Money::lineTotal([$itemData]),
                        'commission_rate' => round((float) $entry['commission_rate'], 2),
                    ]);
                }
            }

            if ($quotedRfqIds !== []) {
                RfqRequest::whereIn('id', $quotedRfqIds)->update(['order_id' => $order->id]);
            }

            return $order->load('subOrders.items');
        });
    }

    /**
     * WA-PRC-02: normal wholesale lines are refused for a buyer whose wholesale access was withdrawn after the cart was
     * filled. Quoted lines are checked against their quotation instead (a retail customer may accept a quote).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertWholesaleAccess(User $customer, array $items): void
    {
        if ($customer->canSeeWholesalePrices()) {
            return;
        }

        $wholesaleChannelId = SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id');
        $hasWholesaleLine = collect($items)->contains(
            fn (array $item): bool => (int) $item['sales_channel_id'] === (int) $wholesaleChannelId && empty($item['is_quoted'])
        );

        if ($hasWholesaleLine) {
            throw new \DomainException('Your account no longer has wholesale access, so wholesale prices can\'t be used. Remove the wholesale items from your cart, or add them again at the retail price.');
        }
    }

    /**
     * Lock each quotation used by a quoted line and check it still covers that line: accepted, not used by another
     * order, this customer's, same product, the quoted price, and exactly the quoted options and quantities.
     * A line from a cart saved before RFQ ids were stored has no id and is not checked here.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<int> the quotation ids to link to the order
     */
    private function lockQuotedRfqs(User $customer, array $items): array
    {
        $linesByRfq = collect($items)->filter(fn (array $item): bool => ! empty($item['rfq_id']))->groupBy(fn (array $item): int => (int) $item['rfq_id']);
        if ($linesByRfq->isEmpty()) {
            return [];
        }

        $rfqs = RfqRequest::whereIn('id', $linesByRfq->keys())->lockForUpdate()->get()->keyBy('id');

        foreach ($linesByRfq as $rfqId => $lines) {
            $rfq = $rfqs->get($rfqId);
            $reason = match (true) {
                $rfq === null => 'it no longer exists',
                $rfq->status !== 'accepted' => 'it is no longer accepted',
                $rfq->order_id !== null => 'it was already used for another order',
                ! $this->isQuotationBuyer($rfq, $customer) => 'it was sent to a different buyer',
                default => $this->quotedLinesMismatch($rfq, $lines->all()),
            };

            if ($reason !== null) {
                throw new \DomainException("Quotation #QT-RFQ-{$rfqId} can't be ordered because {$reason}. Remove it from your cart to continue.");
            }
        }

        return $linesByRfq->keys()->map(fn ($id): int => (int) $id)->all();
    }

    private function isQuotationBuyer(RfqRequest $rfq, User $customer): bool
    {
        return (int) $rfq->buyer_id === (int) $customer->id
            || ($rfq->buyer_email && $customer->email && strtolower($rfq->buyer_email) === strtolower($customer->email));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function quotedLinesMismatch(RfqRequest $rfq, array $lines): ?string
    {
        foreach ($lines as $line) {
            if ((int) $line['product_id'] !== (int) $rfq->product_id) {
                return 'a line is for a different product';
            }
            if (Money::poisha($line['unit_price']) !== Money::poisha($rfq->seller_quoted_price)) {
                return 'its price changed';
            }
        }

        $optionQuantities = $rfq->optionQuantities();
        if ($optionQuantities !== []) {
            $ordered = [];
            foreach ($lines as $line) {
                $ordered[(int) $line['variation_id']] = ($ordered[(int) $line['variation_id']] ?? 0) + (int) $line['quantity'];
            }
            ksort($ordered);
            ksort($optionQuantities);

            return $ordered === $optionQuantities ? null : 'the options or quantities differ from the quotation';
        }

        return array_sum(array_map(fn (array $line): int => (int) $line['quantity'], $lines)) === (int) $rfq->target_quantity
            ? null
            : 'the quantity differs from the quotation';
    }

    /**
     * Seller books: the seller's cost per unit at the moment of ordering (the option's cost, else the product's),
     * so later cost edits never change the profit of this order.
     */
    private function unitCostFor(Product $product, int|string|null $variationId): float
    {
        $variationCost = $variationId !== null
            ? (float) (ProductVariation::query()->whereKey($variationId)->where('product_id', $product->id)->value('buy_price') ?? 0)
            : 0.0;

        return round($variationCost > 0 ? $variationCost : (float) ($product->buy_price ?? 0), 2);
    }
}
