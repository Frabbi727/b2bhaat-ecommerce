<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerWallet extends Model
{
    protected $fillable = [
        'seller_id',
        'available_balance',
        'escrow_held_balance',
    ];

    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:2',
            'escrow_held_balance' => 'decimal:2',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
