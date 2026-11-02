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
        'shipping_amount',
        'is_self_delivered',
        'handover_code',
        'handover_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'is_self_delivered' => 'boolean',
            'handover_confirmed_at' => 'datetime',
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

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function getNetEarningsAttribute(): float
    {
        return (float) ($this->subtotal - $this->commission_amount);
    }

    public function getTrackingUrlAttribute(): ?string
    {
        if (empty($this->tracking_number)) {
            return null;
        }

        $courier = strtolower($this->courier_name ?? '');

        if (str_contains($courier, 'steadfast')) {
            return "https://steadfast.com.bd/t/{$this->tracking_number}";
        }

        if (str_contains($courier, 'pathao')) {
            return "https://merchant.pathao.com/tracking?consignment_id={$this->tracking_number}";
        }

        if (str_contains($courier, 'redx')) {
            return "https://redx.com.bd/track?trackingId={$this->tracking_number}";
        }

        if (str_contains($courier, 'paperfly')) {
            return "https://paperfly.com.bd/tracking?id={$this->tracking_number}";
        }

        if (str_contains($courier, 'sundarban')) {
            return "https://sundarbancourierltd.com/track/{$this->tracking_number}";
        }

        return null;
    }
}
