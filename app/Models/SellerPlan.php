<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_subscription_fee_enabled',
        'subscription_price',
        'billing_cycle_days',
        'is_listing_limit_enabled',
        'max_active_products',
        'is_commission_enabled',
        'retail_commission_rate',
        'wholesale_commission_rate',
        'allow_listing_addons',
        'badge_label',
        'is_default_free_plan',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_subscription_fee_enabled' => 'boolean',
            'subscription_price' => 'decimal:2',
            'billing_cycle_days' => 'integer',
            'is_listing_limit_enabled' => 'boolean',
            'max_active_products' => 'integer',
            'is_commission_enabled' => 'boolean',
            'retail_commission_rate' => 'decimal:2',
            'wholesale_commission_rate' => 'decimal:2',
            'allow_listing_addons' => 'boolean',
            'is_default_free_plan' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SellerSubscription::class, 'plan_id');
    }

    public function isFree(): bool
    {
        return ! $this->is_subscription_fee_enabled || (float) $this->subscription_price <= 0.00;
    }
}
