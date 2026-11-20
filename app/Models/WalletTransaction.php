<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Observers\WalletTransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(WalletTransactionObserver::class)]
class WalletTransaction extends Model
{
    use Auditable;

    public const TYPE_ESCROW_HOLD = 'escrow_hold';

    public const TYPE_ESCROW_RELEASE = 'escrow_release';

    public const TYPE_ESCROW_REFUND = 'escrow_refund';

    public const TYPE_PAYOUT = 'payout';

    /** Self-delivery fee added to an existing prepaid hold (D6). */
    public const TYPE_ESCROW_HOLD_ADJUSTMENT = 'escrow_hold_adjustment';

    /** Seller credit taken back after a released sub-order is returned; may leave available balance negative. */
    public const TYPE_RETURN_REVERSAL = 'return_reversal';

    /**
     * Self-delivered COD: the seller kept the buyer's cash. Amount = what the seller owes the platform
     * (cash collected − seller's share), debited from available balance (final review C2).
     */
    public const TYPE_COD_SELF_COLLECTED = 'cod_self_collected';

    /** Undo of a self-collected COD charge when that consignment is returned. */
    public const TYPE_COD_SELF_COLLECTED_REVERSAL = 'cod_self_collected_reversal';

    /** Rejected payout credited back to available balance (PRA-013). */
    public const TYPE_PAYOUT_REVERSAL = 'payout_reversal';

    /** MON-11: the seller paid a COD commission deficit bill, which brings the wallet back up. */
    public const TYPE_COD_DEFICIT_PAYMENT = 'cod_deficit_payment';

    /** PAY-4: items returned while the money was still on hold; the hold shrinks by this much. */
    public const TYPE_PARTIAL_RETURN_HELD = 'partial_return_held';

    /** PAY-4: items returned after the money was released; taken from the available balance. */
    public const TYPE_PARTIAL_RETURN_RELEASED = 'partial_return_released';

    /** PAY-4: items returned from a parcel whose COD cash the seller kept; commission share given back. */
    public const TYPE_PARTIAL_RETURN_COD = 'partial_return_cod';

    /**
     * Owner decision (item 8): items came back from a parcel whose COD cash the seller kept. B2Bhaat refunds the
     * buyer through the refund queue and takes the same amount from the seller's balance (it may go below zero).
     */
    public const TYPE_COD_RETURN_REFUND = 'cod_return_refund';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_DISBURSED = 'disbursed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'seller_wallet_id',
        'sub_order_id',
        'return_request_id',
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
