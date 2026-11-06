<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerSubscription extends Model
{
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
        ];
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
