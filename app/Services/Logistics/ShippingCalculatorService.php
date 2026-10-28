<?php

namespace App\Services\Logistics;

use App\Models\Product;

class ShippingCalculatorService
{
    public const DEFAULT_INSIDE_DHAKA_RATE = 70.00;

    public const DEFAULT_OUTSIDE_DHAKA_RATE = 130.00;

    public const DEFAULT_FREE_SHIPPING_THRESHOLD = 10000.00;

    /**
     * Calculate delivery fees partitioned per vendor based on Decision D6 rules.
     *
     * @param array<int, array{
     *     product_id: int,
     *     quantity: int,
     *     unit_price: float,
     *     variation_id?: ?int,
     *     sales_channel_id?: int
     * }> $items
     * @param  string  $deliveryZone  'inside_dhaka' or 'outside_dhaka'
     * @return array{
     *     total_shipping: float,
     *     vendors: array<int, array{
     *         store_name: string,
     *         subtotal: float,
     *         shipping_fee: float,
     *         is_free: bool,
     *         threshold: float,
     *         remaining_for_free: float
     *     }>
     * }
     */
    public function calculateShipping(array $items, string $deliveryZone = 'inside_dhaka'): array
    {
        if (empty($items)) {
            return [
                'total_shipping' => 0.00,
                'vendors' => [],
            ];
        }

        // 1. Group items by seller
        $productIds = collect($items)->pluck('product_id')->unique()->filter();
        $products = Product::with('seller.sellerProfile')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $vendorGroups = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                continue;
            }

            $sellerId = $product->seller_id;
            $vendorGroups[$sellerId]['product'] = $product;
            $vendorGroups[$sellerId]['items'][] = $item;
        }

        $totalShipping = 0.00;
        $vendorBreakdown = [];

        foreach ($vendorGroups as $sellerId => $group) {
            $product = $group['product'];
            $seller = $product->seller;
            $profile = $seller?->sellerProfile;

            $vendorSubtotal = 0.00;
            foreach ($group['items'] as $item) {
                $vendorSubtotal += ($item['unit_price'] * $item['quantity']);
            }

            // Resolve threshold
            $threshold = $profile?->free_shipping_threshold !== null
                ? (float) $profile->free_shipping_threshold
                : self::DEFAULT_FREE_SHIPPING_THRESHOLD;

            $isFree = $vendorSubtotal >= $threshold;

            if ($isFree) {
                $vendorShipping = 0.00;
            } else {
                if ($deliveryZone === 'outside_dhaka') {
                    $vendorShipping = $profile?->outside_dhaka_rate !== null
                        ? (float) $profile->outside_dhaka_rate
                        : self::DEFAULT_OUTSIDE_DHAKA_RATE;
                } else {
                    $vendorShipping = $profile?->inside_dhaka_rate !== null
                        ? (float) $profile->inside_dhaka_rate
                        : self::DEFAULT_INSIDE_DHAKA_RATE;
                }
            }

            $totalShipping += $vendorShipping;

            $vendorBreakdown[$sellerId] = [
                'store_name' => $profile?->store_name ?? 'Factory Merchant',
                'subtotal' => round($vendorSubtotal, 2),
                'shipping_fee' => round($vendorShipping, 2),
                'is_free' => $isFree,
                'threshold' => round($threshold, 2),
                'remaining_for_free' => round(max(0.0, $threshold - $vendorSubtotal), 2),
            ];
        }

        return [
            'total_shipping' => round($totalShipping, 2),
            'vendors' => $vendorBreakdown,
        ];
    }
}
