<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    use Auditable;

    protected $fillable = [
        'order_number',
        'customer_id',
        'order_status_id',
        'payment_status_id',
        'payment_method_id',
        'coupon_id',
        'coupon_code',
        'shipping_address',
        'total_amount',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'invoice_token',
        'tracking_token',
        'sms_confirmation_sent_at',
    ];

    /**
     * Orders whose recipient or customer phone is exactly this number, in any stored Bangladeshi format.
     * An invalid or partial number matches nothing.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeMatchingPhone(Builder $query, string $phone): void
    {
        if (! PhoneNormalizer::isValid($phone)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $normalized = PhoneNormalizer::normalize($phone);
        $formats = [$normalized, '+'.$normalized, substr($normalized, 2)];

        $query->where(function (Builder $inner) use ($formats, $normalized) {
            $inner->whereIn('shipping_address->recipient_phone', $formats)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->wherePhone($normalized));
        });
    }

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'total_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'sms_confirmation_sent_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Order $order): void {
            if (empty($order->invoice_token)) {
                $order->invoice_token = bin2hex(random_bytes(20));
            }
            if (empty($order->tracking_token)) {
                $order->tracking_token = bin2hex(random_bytes(20));
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function orderStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function paymentStatus(): BelongsTo
    {
        return $this->belongsTo(PaymentStatus::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class, 'parent_order_id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, SubOrder::class, 'parent_order_id', 'sub_order_id');
    }

    public function getGrandTotalAttribute(): float
    {
        return (float) $this->total_amount;
    }
}
