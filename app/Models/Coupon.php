<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'max_discount_amount',
        'usage_limit_total',
        'usage_limit_per_user',
        'used_count',
        'start_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'usage_limit_total' => 'integer',
            'usage_limit_per_user' => 'integer',
            'used_count' => 'integer',
            'start_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });
    }

    /**
     * Check validity for given cart subtotal and optional customer user id.
     *
     * @return array{valid: bool, message: string}
     */
    public function validateForSubtotal(float $subtotal, ?int $userId = null): array
    {
        if (! $this->is_active) {
            return ['valid' => false, 'message' => 'This coupon is no longer active.'];
        }

        $now = now();
        if ($this->start_at && $this->start_at->isFuture()) {
            return ['valid' => false, 'message' => 'This coupon promotion has not started yet.'];
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($this->usage_limit_total && $this->used_count >= $this->usage_limit_total) {
            return ['valid' => false, 'message' => 'This coupon has reached its maximum total usage limit.'];
        }

        if ($subtotal < (float) $this->min_order_amount) {
            $formattedMin = number_format((float) $this->min_order_amount, 2);

            return [
                'valid' => false,
                'message' => "This coupon requires a minimum subtotal of ৳{$formattedMin}. Current subtotal: ৳".number_format($subtotal, 2),
            ];
        }

        if ($userId && $this->usage_limit_per_user) {
            $userUsage = $this->usages()->where('user_id', $userId)->count();
            if ($userUsage >= $this->usage_limit_per_user) {
                return ['valid' => false, 'message' => 'You have already utilized this coupon code.'];
            }
        }

        return ['valid' => true, 'message' => 'Coupon code applied successfully!'];
    }

    /**
     * Compute monetary discount for given subtotal.
     */
    public function calculateDiscount(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.00;
        }

        if ($this->discount_type === self::TYPE_PERCENTAGE) {
            $discount = round(($subtotal * (float) $this->discount_value) / 100, 2);
            if ($this->max_discount_amount && $discount > (float) $this->max_discount_amount) {
                $discount = (float) $this->max_discount_amount;
            }

            return min($discount, $subtotal);
        }

        // Fixed discount
        return min((float) $this->discount_value, $subtotal);
    }
}
