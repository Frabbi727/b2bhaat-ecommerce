<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Exceptions\ListingActivationRefusedException;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;

class MonetizationEngineService
{
    public function __construct(
        protected StoreConfigurationService $config,
        protected DefaultPlanService $defaultPlans,
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
     * Order: the admin's custom rate on the subscription, then the plan terms the subscription was sold on (MON-2),
     * then the rate set when the seller was approved. Zero while marketplace fees are switched off (MON-8).
     */
    public function resolveCommissionRate(User $seller, Product $product, SalesChannel $channel): float
    {
        if (! $this->config->isMonetizationEnabled()) {
            return 0.00;
        }

        // The seller's custom rate, then the plan, then the default. There are no per-category rates (owner
        // decision MON-16), so the product's category plays no part.
        return $this->sellerCommissionRate($seller, $channel->slug === SalesChannel::WHOLESALE);
    }

    /**
     * UX-5: the seller's commission rate for a channel; resolveCommissionRate() charges exactly this.
     */
    public function sellerCommissionRate(User $seller, bool $wholesale): float
    {
        if (! $this->config->isMonetizationEnabled()) {
            return 0.00;
        }

        $subscription = $this->getActiveSubscription($seller);

        return $this->customCommissionRate($subscription, $wholesale ? SalesChannel::WHOLESALE : SalesChannel::RETAIL)
            ?? $this->planOrDefaultCommissionRate($seller, $subscription, $wholesale);
    }

    private function customCommissionRate(?SellerSubscription $subscription, ?string $channelSlug): ?float
    {
        if (! $subscription) {
            return null;
        }

        if ($channelSlug === SalesChannel::WHOLESALE && $subscription->custom_wholesale_commission !== null) {
            return (float) $subscription->custom_wholesale_commission;
        }

        if ($channelSlug === SalesChannel::RETAIL && $subscription->custom_retail_commission !== null) {
            return (float) $subscription->custom_retail_commission;
        }

        return null;
    }

    private function planOrDefaultCommissionRate(User $seller, ?SellerSubscription $subscription, bool $wholesale): float
    {
        if ($subscription && ($subscription->hasCapturedTerms() || $subscription->plan)) {
            if (! $subscription->termCommissionEnabled()) {
                return 0.00;
            }

            return $subscription->termCommissionRate($wholesale);
        }

        return (float) ($seller->sellerProfile?->commission_rate_percentage ?? 10.00);
    }

    /**
     * Check whether a seller has capacity to activate or publish another product.
     */
    public function canActivateMoreProducts(User $seller): bool
    {
        $remaining = $this->remainingActivationSlots($seller);

        return $remaining === null || $remaining > 0;
    }

    /**
     * How many more products the seller may make visible; null means no limit applies.
     */
    public function remainingActivationSlots(User $seller): ?int
    {
        if (! $this->config->isMonetizationEnabled()) {
            return null;
        }

        $limit = $this->getAllowedProductCount($seller);
        if ($limit === null) {
            return null;
        }

        $currentActiveCount = Product::where('seller_id', $seller->id)
            ->where('is_active', true)
            ->count();

        return max(0, $limit - $currentActiveCount);
    }

    /**
     * The one check every "make visible" path uses: refused while the store is suspended or when
     * showing $count more products would pass the plan's listing limit (INV-1, INV-2, MON-9).
     *
     * @throws ListingActivationRefusedException
     */
    public function assertCanActivate(User $seller, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        if ($seller->sellerProfile?->monetization_status === 'suspended') {
            throw new ListingActivationRefusedException('Your store is suspended, so products cannot be shown to buyers. Settle your payment to restore it, or keep them as hidden drafts.');
        }

        $remaining = $this->remainingActivationSlots($seller);
        if ($remaining !== null && $count > $remaining) {
            $slots = $remaining === 1 ? '1 more product' : "{$remaining} more products";
            throw new ListingActivationRefusedException("Your plan lets you show {$slots}, and you asked to show {$count}. Upgrade your plan or hide other products first.");
        }
    }

    /**
     * Get the total allowed active product slots (plan limit + purchased add-on packs).
     */
    public function getAllowedProductCount(User $seller): ?int
    {
        $subscription = $this->getActiveSubscription($seller);

        if (! $subscription) {
            // No running plan (never assigned, or ended): the default free plan's limit applies (MON-3).
            $defaultPlan = $this->defaultPlans->defaultPlan();
            if (! $defaultPlan || ! $defaultPlan->is_listing_limit_enabled || $defaultPlan->max_active_products === null) {
                return null; // Unlimited
            }

            return (int) $defaultPlan->max_active_products + $this->getActiveListingAddonSlots($seller);
        }

        if ((! $subscription->hasCapturedTerms() && ! $subscription->plan) || ! $subscription->termListingLimitEnabled()) {
            return null; // Unlimited
        }

        // The limit this subscription was sold on, or the admin's custom limit (MON-2)
        $baseLimit = $subscription->termMaxActiveProducts();
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
