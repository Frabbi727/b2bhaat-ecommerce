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
        'is_approved',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate_percentage' => 'decimal:2',
            'is_approved' => 'boolean',
        ];
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
