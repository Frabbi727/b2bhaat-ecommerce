<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'customer_id',
        'order_status_id',
        'payment_status_id',
        'payment_method_id',
        'shipping_address',
        'total_amount',
        'tax_amount',
        'shipping_amount',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'total_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
        ];
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

    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class, 'parent_order_id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, SubOrder::class, 'parent_order_id', 'sub_order_id');
    }
}
