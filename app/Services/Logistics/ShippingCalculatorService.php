<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Models\Product;
use App\Services\Formatting\Money;
use App\Services\Settings\StoreConfigurationService;
use Illuminate\Support\Collection;

class ShippingCalculatorService
{
    public const DEFAULT_INSIDE_DHAKA_RATE = 70.00;

    public const DEFAULT_OUTSIDE_DHAKA_RATE = 130.00;

    public const DEFAULT_FREE_SHIPPING_THRESHOLD = 10000.00;

    /** Delivery zones a buyer can choose, in display order. */
    public const ZONES = ['inside_dhaka', 'sub_dhaka', 'outside_dhaka'];

    /** Buyer-facing zone names. */
    public const ZONE_LABELS = [
        'inside_dhaka' => 'Inside Dhaka',
        'sub_dhaka' => 'Sub-Dhaka (Savar, Gazipur, Narayanganj, Keraniganj)',
        'outside_dhaka' => 'Outside Dhaka (all other districts)',
    ];

    public function __construct(
        protected ?StoreConfigurationService $config = null
    ) {
        $this->config = $config ?? app(StoreConfigurationService::class);
    }

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
     * @param  string  $deliveryZone  'inside_dhaka', 'sub_dhaka', or 'outside_dhaka'
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

        return $this->quote($items, $deliveryZone, $this->productsFor($items));
    }

    /**
     * Roadmap 4.4: the quote for every delivery zone, loading the products and their sellers once instead of once
     * per zone. Each zone's quote is identical to calculateShipping() for that zone.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float}>  $items
     * @return array<string, array{total_shipping: float, vendors: array<int, array<string, mixed>>}>
     */
    public function calculateAllZones(array $items): array
    {
        $products = empty($items) ? collect() : $this->productsFor($items);

        $quotes = [];
        foreach (self::ZONES as $zone) {
            $quotes[$zone] = empty($items)
                ? ['total_shipping' => 0.00, 'vendors' => []]
                : $this->quote($items, $zone, $products);
        }

        return $quotes;
    }

    /**
     * @param  array<int, array{product_id: int}>  $items
     * @return Collection<int, Product>
     */
    private function productsFor(array $items): Collection
    {
        return Product::with('seller.sellerProfile')
            ->whereIn('id', collect($items)->pluck('product_id')->unique()->filter())
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float}>  $items
     * @param  Collection<int, Product>  $products
     * @return array{total_shipping: float, vendors: array<int, array<string, mixed>>}
     */
    private function quote(array $items, string $deliveryZone, Collection $products): array
    {
        // 1. Group items by seller

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

            $vendorSubtotal = Money::lineTotal($group['items']);

            // Resolve threshold: seller override, or dynamic platform default
            $threshold = $profile?->free_shipping_threshold !== null
                ? (float) $profile->free_shipping_threshold
                : $this->config->getFreeShippingThreshold();

            $isFree = $vendorSubtotal >= $threshold;

            if ($isFree) {
                $vendorShipping = 0.00;
            } else {
                if ($deliveryZone === 'outside_dhaka' && $profile?->outside_dhaka_rate !== null) {
                    $vendorShipping = (float) $profile->outside_dhaka_rate;
                } elseif ($deliveryZone === 'inside_dhaka' && $profile?->inside_dhaka_rate !== null) {
                    $vendorShipping = (float) $profile->inside_dhaka_rate;
                } else {
                    $vendorShipping = $this->config->getDeliveryFee($deliveryZone);
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
