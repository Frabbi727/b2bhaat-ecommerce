<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SellerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'store_name',
        'store_slug',
        'trade_license_number',
        'tax_id_number',
        'commission_rate_percentage',
        'inside_dhaka_rate',
        'outside_dhaka_rate',
        'free_shipping_threshold',
        'is_approved',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'is_on_vacation',
        'vacation_message',
        'vacation_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate_percentage' => 'decimal:2',
            'inside_dhaka_rate' => 'decimal:2',
            'outside_dhaka_rate' => 'decimal:2',
            'free_shipping_threshold' => 'decimal:2',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'is_on_vacation' => 'boolean',
            'vacation_ends_at' => 'date',
        ];
    }

    public function isPending(): bool
    {
        return ! $this->is_approved && $this->rejected_at === null;
    }

    public function isRejected(): bool
    {
        return ! $this->is_approved && $this->rejected_at !== null;
    }

    public function isApproved(): bool
    {
        return (bool) $this->is_approved;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, User::class, 'id', 'seller_id', 'user_id', 'id');
    }
}
