<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;

class MonetizationEngineService
{
    public function __construct(
        protected StoreConfigurationService $config
    ) {}

    /**
     * Get the currently active or in-grace subscription for the seller.
     */
    public function getActiveSubscription(User $seller): ?SellerSubscription
    {
        return SellerSubscription::with('plan')
            ->where('seller_id', $seller->id)
            ->whereIn('status', [SellerSubscription::STATUS_ACTIVE, SellerSubscription::STATUS_GRACE_PERIOD])
            ->where('starts_at', '<=', now())
            ->where(function ($query): void {
                $query->where('ends_at', '>', now())
                    ->orWhere(function ($graceQuery): void {
                        $graceQuery->where('status', SellerSubscription::STATUS_GRACE_PERIOD)
                            ->where(function ($g): void {
                                $g->whereNull('grace_period_ends_at')
                                    ->orWhere('grace_period_ends_at', '>', now());
                            });
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the dynamic commission percentage for a specific seller, product, and channel.
     */
    public function resolveCommissionRate(User $seller, Product $product, SalesChannel $channel): float
    {
        if (! $this->config->isMonetizationEnabled()) {
            return 0.00;
        }

        $subscription = $this->getActiveSubscription($seller);

        // 1. Check seller custom override on subscription
        if ($subscription) {
            if ($channel->slug === SalesChannel::WHOLESALE && $subscription->custom_wholesale_commission !== null) {
                return (float) $subscription->custom_wholesale_commission;
            }

            if ($channel->slug === SalesChannel::RETAIL && $subscription->custom_retail_commission !== null) {
                return (float) $subscription->custom_retail_commission;
            }
        }

        // 2. Check category override if available
        if (isset($product->category->commission_rate_percentage) && $product->category->commission_rate_percentage !== null) {
            return (float) $product->category->commission_rate_percentage;
        }

        // 3. Check active plan
        if ($subscription && $subscription->plan) {
            if (! $subscription->plan->is_commission_enabled) {
                return 0.00;
            }

            return (float) ($channel->slug === SalesChannel::WHOLESALE
                ? $subscription->plan->wholesale_commission_rate
                : $subscription->plan->retail_commission_rate);
        }

        // 4. Fallback to seller profile default
        return (float) ($seller->sellerProfile?->commission_rate_percentage ?? 10.00);
    }

    /**
     * Check whether a seller has capacity to activate or publish another product.
     */
    public function canActivateMoreProducts(User $seller): bool
    {
        if (! $this->config->isMonetizationEnabled()) {
            return true;
        }

        $subscription = $this->getActiveSubscription($seller);

        // If no subscription or plan has listing limits disabled -> unlimited
        if (! $subscription || ! $subscription->plan || ! $subscription->plan->is_listing_limit_enabled) {
            return true;
        }

        $limit = $this->getAllowedProductCount($seller);
        if ($limit === null) {
            return true; // Unlimited
        }

        $currentActiveCount = Product::where('seller_id', $seller->id)
            ->where('is_active', true)
            ->count();

        return $currentActiveCount < $limit;
    }

    /**
     * Get the total allowed active product slots (plan limit + purchased add-on packs).
     */
    public function getAllowedProductCount(User $seller): ?int
    {
        $subscription = $this->getActiveSubscription($seller);
        if (! $subscription || ! $subscription->plan || ! $subscription->plan->is_listing_limit_enabled) {
            return null; // Unlimited
        }

        $baseLimit = $subscription->custom_product_limit ?? $subscription->plan->max_active_products;
        if ($baseLimit === null) {
            return null;
        }

        $addonSlots = $this->getActiveListingAddonSlots($seller);

        return $baseLimit + $addonSlots;
    }

    /**
     * Get count of additional active product slots purchased via listing packs.
     */
    public function getActiveListingAddonSlots(User $seller): int
    {
        $paidListingPackInvoices = SellerInvoice::where('seller_id', $seller->id)
            ->where('invoice_type', SellerInvoice::TYPE_LISTING_PACK)
            ->where('status', SellerInvoice::STATUS_PAID)
            ->where('paid_at', '>=', now()->subDays(30))
            ->get();

        $totalSlots = 0;
        foreach ($paidListingPackInvoices as $invoice) {
            $totalSlots += (int) ($invoice->metadata['extra_slots'] ?? $this->config->getListingAddonPackSize());
        }

        return $totalSlots;
    }
}
