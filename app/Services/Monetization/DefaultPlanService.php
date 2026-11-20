<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MON-3: every approved seller is on a plan. A seller with no running subscription is given the plan marked
 * "Default Free Plan for New Sellers", and its listing limit is what applies when a seller's plan has ended.
 */
class DefaultPlanService
{
    /** Statuses that mean the seller already has a plan (a suspended one is restored, not replaced). */
    public const RUNNING_STATUSES = [
        SellerSubscription::STATUS_ACTIVE,
        SellerSubscription::STATUS_GRACE_PERIOD,
        SellerSubscription::STATUS_SUSPENDED,
    ];

    /**
     * The active plan marked as the default for new sellers, if the admin has set one.
     */
    public function defaultPlan(): ?SellerPlan
    {
        return SellerPlan::query()
            ->where('is_default_free_plan', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function hasRunningSubscription(User $seller): bool
    {
        return SellerSubscription::where('seller_id', $seller->id)
            ->whereIn('status', self::RUNNING_STATUSES)
            ->exists();
    }

    /**
     * Puts the seller on the default plan unless they already have a plan. Returns the new subscription, or null
     * when nothing was created (already on a plan, or no default plan is set).
     */
    public function assignIfMissing(User $seller): ?SellerSubscription
    {
        $plan = $this->defaultPlan();

        if (! $plan) {
            return null;
        }

        return DB::transaction(function () use ($seller, $plan): ?SellerSubscription {
            // One assignment per seller, even if approval and the backfill run at the same moment.
            User::whereKey($seller->id)->lockForUpdate()->first();

            if ($this->hasRunningSubscription($seller)) {
                return null;
            }

            return SellerSubscription::create([
                'seller_id' => $seller->id,
                'plan_id' => $plan->id,
                'status' => SellerSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addDays($plan->billing_cycle_days ?: 365),
                'auto_renew' => true,
            ]);
        });
    }
}
