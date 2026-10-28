<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_ESCROW_HOLD = 'escrow_hold';

    public const TYPE_ESCROW_RELEASE = 'escrow_release';

    public const TYPE_PAYOUT = 'payout';

    protected $fillable = [
        'seller_wallet_id',
        'sub_order_id',
        'transaction_type',
        'amount',
        'running_balance',
        'reference_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SellerWallet::class, 'seller_wallet_id');
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }
}
