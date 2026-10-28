<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubOrder extends Model
{
    protected $fillable = [
        'parent_order_id',
        'seller_id',
        'sub_order_number',
        'fulfillment_status_id',
        'courier_name',
        'tracking_number',
        'subtotal',
        'commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'commission_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function fulfillmentStatus(): BelongsTo
    {
        return $this->belongsTo(FulfillmentStatus::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function trackingLogs(): HasMany
    {
        return $this->hasMany(OrderTrackingLog::class)->orderBy('logged_at', 'desc');
    }

    public function getNetEarningsAttribute(): float
    {
        return (float) ($this->subtotal - $this->commission_amount);
    }
}
