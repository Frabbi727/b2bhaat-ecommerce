<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Services\Sms\OrderNotificationService;

/**
 * MON-2: when an admin changes a plan's price, billing cycle, listing limit or commission, sellers already on the
 * plan keep their terms until renewal. This tells each of them so.
 */
class PlanTermsChangeNotifier
{
    /** Plan columns that are part of a seller's terms. */
    public const TERM_FIELDS = [
        'subscription_price',
        'billing_cycle_days',
        'is_listing_limit_enabled',
        'max_active_products',
        'is_commission_enabled',
        'retail_commission_rate',
        'wholesale_commission_rate',
    ];

    public function __construct(
        private readonly OrderNotificationService $notifications,
    ) {}

    /**
     * Whether the plan's last save changed any term.
     */
    public function termsChanged(SellerPlan $plan): bool
    {
        return $plan->wasChanged(self::TERM_FIELDS);
    }

    /**
     * Notifies every seller with a running subscription on this plan; returns how many subscriptions keep their terms.
     */
    public function notifySubscribers(SellerPlan $plan): int
    {
        $subscriptions = SellerSubscription::with('seller')
            ->where('plan_id', $plan->id)
            ->whereIn('status', [
                SellerSubscription::STATUS_ACTIVE,
                SellerSubscription::STATUS_GRACE_PERIOD,
                SellerSubscription::STATUS_SUSPENDED,
            ])
            ->get();

        foreach ($subscriptions as $subscription) {
            if ($subscription->seller) {
                $this->notifications->sendPlanTermsChangeNotice($subscription->seller, $subscription, $plan->name);
            }
        }

        return $subscriptions->count();
    }
}
