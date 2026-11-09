<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SellerSubscription extends Model
{
    use Auditable;
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAYMENT_DUE = 'payment_due';

    public const STATUS_GRACE_PERIOD = 'grace_period';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'seller_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'grace_period_ends_at',
        'auto_renew',
        'custom_product_limit',
        'custom_retail_commission',
        'custom_wholesale_commission',
        'custom_subscription_price',
        'admin_override_notes',
        'term_subscription_price',
        'term_billing_cycle_days',
        'term_is_listing_limit_enabled',
        'term_max_active_products',
        'term_is_commission_enabled',
        'term_retail_commission_rate',
        'term_wholesale_commission_rate',
        'terms_captured_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'auto_renew' => 'boolean',
            'custom_product_limit' => 'integer',
            'custom_retail_commission' => 'decimal:2',
            'custom_wholesale_commission' => 'decimal:2',
            'custom_subscription_price' => 'decimal:2',
            'term_subscription_price' => 'decimal:2',
            'term_billing_cycle_days' => 'integer',
            'term_is_listing_limit_enabled' => 'boolean',
            'term_max_active_products' => 'integer',
            'term_is_commission_enabled' => 'boolean',
            'term_retail_commission_rate' => 'decimal:2',
            'term_wholesale_commission_rate' => 'decimal:2',
            'terms_captured_at' => 'datetime',
        ];
    }

    /**
     * Every new subscription keeps the terms of the plan it was sold on (MON-2).
     */
    protected static function booted(): void
    {
        static::creating(function (SellerSubscription $subscription): void {
            if ($subscription->terms_captured_at !== null) {
                return;
            }

            $plan = $subscription->plan_id ? SellerPlan::find($subscription->plan_id) : null;
            if ($plan) {
                $subscription->forceFill(self::termsFromPlan($plan));
            }
        });

        // Moving a subscription to another plan brings that plan's terms, unless the caller sets terms itself.
        static::updating(function (SellerSubscription $subscription): void {
            if (! $subscription->isDirty('plan_id') || $subscription->isDirty('terms_captured_at')) {
                return;
            }

            $plan = $subscription->plan_id ? SellerPlan::find($subscription->plan_id) : null;
            if ($plan) {
                $subscription->forceFill(self::termsFromPlan($plan));
            }
        });
    }

    /**
     * The plan's current terms as subscription columns.
     *
     * @return array{term_subscription_price: float, term_billing_cycle_days: int, term_is_listing_limit_enabled: bool, term_max_active_products: ?int, term_is_commission_enabled: bool, term_retail_commission_rate: float, term_wholesale_commission_rate: float, terms_captured_at: Carbon}
     */
    public static function termsFromPlan(SellerPlan $plan): array
    {
        return [
            'term_subscription_price' => (float) $plan->subscription_price,
            'term_billing_cycle_days' => (int) $plan->billing_cycle_days,
            'term_is_listing_limit_enabled' => (bool) $plan->is_listing_limit_enabled,
            'term_max_active_products' => $plan->max_active_products !== null ? (int) $plan->max_active_products : null,
            'term_is_commission_enabled' => (bool) $plan->is_commission_enabled,
            'term_retail_commission_rate' => (float) $plan->retail_commission_rate,
            'term_wholesale_commission_rate' => (float) $plan->wholesale_commission_rate,
            'terms_captured_at' => now(),
        ];
    }

    /**
     * Replace the kept terms with the plan's current ones; used when a new period starts (renewal).
     */
    public function refreshTermsFromPlan(): void
    {
        $plan = $this->plan()->first();
        if ($plan) {
            $this->forceFill(self::termsFromPlan($plan))->save();
        }
    }

    public function hasCapturedTerms(): bool
    {
        return $this->terms_captured_at !== null;
    }

    /**
     * The price of one billing period: the admin's custom price, else the kept term, else the plan.
     */
    public function termSubscriptionPrice(): float
    {
        if ($this->custom_subscription_price !== null) {
            return (float) $this->custom_subscription_price;
        }

        return (float) ($this->hasCapturedTerms() ? $this->term_subscription_price : ($this->plan?->subscription_price ?? 0.00));
    }

    public function termBillingCycleDays(): ?int
    {
        $days = $this->hasCapturedTerms() ? $this->term_billing_cycle_days : $this->plan?->billing_cycle_days;

        return $days ? (int) $days : null;
    }

    public function termListingLimitEnabled(): bool
    {
        return (bool) ($this->hasCapturedTerms() ? $this->term_is_listing_limit_enabled : $this->plan?->is_listing_limit_enabled);
    }

    /**
     * Products the seller may show before add-on packs; null means no limit.
     */
    public function termMaxActiveProducts(): ?int
    {
        if ($this->custom_product_limit !== null) {
            return (int) $this->custom_product_limit;
        }

        $limit = $this->hasCapturedTerms() ? $this->term_max_active_products : $this->plan?->max_active_products;

        return $limit !== null ? (int) $limit : null;
    }

    public function termCommissionEnabled(): bool
    {
        return (bool) ($this->hasCapturedTerms() ? $this->term_is_commission_enabled : $this->plan?->is_commission_enabled);
    }

    public function termCommissionRate(bool $wholesale): float
    {
        if (! $this->hasCapturedTerms()) {
            return (float) ($wholesale ? $this->plan?->wholesale_commission_rate : $this->plan?->retail_commission_rate);
        }

        return (float) ($wholesale ? $this->term_wholesale_commission_rate : $this->term_retail_commission_rate);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SellerPlan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SellerInvoice::class, 'subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->ends_at->isFuture();
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === self::STATUS_GRACE_PERIOD && ($this->grace_period_ends_at === null || $this->grace_period_ends_at->isFuture());
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
