<?php

namespace App\Services\Orders;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Inventory\StockReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderSplitterService
{
    public function __construct(
        protected StockReservationService $stockReservationService
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
     *         unit_price: float
     *     }>
     * } $data
     */
    public function createAndSplitOrder(User $customer, array $data): Order
    {
        return DB::transaction(function () use ($customer, $data) {
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

            // Calculate total amount
            $itemsTotal = 0.0;
            foreach ($data['items'] as $item) {
                $itemsTotal += ($item['unit_price'] * $item['quantity']);
            }
            $shippingAmount = isset($data['shipping_amount']) ? (float) $data['shipping_amount'] : 0.0;
            $grandTotal = $itemsTotal + $shippingAmount;

            $orderNumber = 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(6));

            // 3. Create master Order
            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customer->id,
                'order_status_id' => $pendingStatus->id,
                'payment_status_id' => $unpaidStatus->id,
                'payment_method_id' => $data['payment_method_id'],
                'shipping_address' => $data['shipping_address'],
                'total_amount' => $grandTotal,
                'tax_amount' => 0.00,
                'shipping_amount' => $shippingAmount,
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

                $subtotal = 0.0;
                $commissionAmount = 0.0;

                // Calculate subtotal and commission
                $seller = $sellerItemList[0]['product']->seller;
                $defaultCommissionRate = $seller?->sellerProfile?->commission_rate_percentage ?? 10.00;

                foreach ($sellerItemList as $entry) {
                    $itemData = $entry['item_data'];
                    $lineTotal = $itemData['unit_price'] * $itemData['quantity'];
                    $subtotal += $lineTotal;

                    // Channel-specific take rate (Retail: 10%, Wholesale: 4%)
                    $channel = SalesChannel::find($itemData['sales_channel_id']);
                    $rate = ($channel && $channel->slug === SalesChannel::WHOLESALE) ? 4.00 : (float) $defaultCommissionRate;
                    $commissionAmount += ($lineTotal * ($rate / 100.0));
                }

                $subOrder = SubOrder::create([
                    'parent_order_id' => $order->id,
                    'seller_id' => $sellerId,
                    'sub_order_number' => $subOrderNumber,
                    'fulfillment_status_id' => $unfulfilledStatus->id,
                    'subtotal' => $subtotal,
                    'commission_amount' => round($commissionAmount, 2),
                ]);

                foreach ($sellerItemList as $entry) {
                    $itemData = $entry['item_data'];
                    OrderItem::create([
                        'sub_order_id' => $subOrder->id,
                        'product_id' => $itemData['product_id'],
                        'variation_id' => $itemData['variation_id'] ?? null,
                        'sales_channel_id' => $itemData['sales_channel_id'],
                        'unit_price' => $itemData['unit_price'],
                        'quantity' => $itemData['quantity'],
                        'total_price' => round($itemData['unit_price'] * $itemData['quantity'], 2),
                    ]);
                }
            }

            return $order->load('subOrders.items');
        });
    }
}
