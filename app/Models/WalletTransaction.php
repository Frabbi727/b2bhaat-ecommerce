<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_ESCROW_HOLD = 'escrow_hold';

    public const TYPE_ESCROW_RELEASE = 'escrow_release';

    public const TYPE_ESCROW_REFUND = 'escrow_refund';

    public const TYPE_PAYOUT = 'payout';

    /** Self-delivery fee added to an existing prepaid hold (D6). */
    public const TYPE_ESCROW_HOLD_ADJUSTMENT = 'escrow_hold_adjustment';

    /** Seller credit taken back after a released sub-order is returned; may leave available balance negative. */
    public const TYPE_RETURN_REVERSAL = 'return_reversal';

    /** Rejected payout credited back to available balance (PRA-013). */
    public const TYPE_PAYOUT_REVERSAL = 'payout_reversal';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_DISBURSED = 'disbursed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'seller_wallet_id',
        'sub_order_id',
        'transaction_type',
        'status',
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

    public function isPendingPayout(): bool
    {
        return $this->transaction_type === self::TYPE_PAYOUT && $this->status === self::STATUS_REQUESTED;
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
