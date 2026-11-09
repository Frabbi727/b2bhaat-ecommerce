<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerInvoice extends Model
{
    use Auditable;
    use HasFactory;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    public const TYPE_SUBSCRIPTION_FEE = 'subscription_fee';

    public const TYPE_LISTING_PACK = 'listing_pack';

    public const TYPE_COD_COMMISSION_DEFICIT = 'cod_commission_deficit';

    public const TYPE_MANUAL_FEE = 'manual_fee';

    protected $fillable = [
        'invoice_number',
        'seller_id',
        'subscription_id',
        'invoice_type',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'due_date',
        'paid_at',
        'metadata',
        'billing_period_key',
    ];

    /**
     * The key that makes a renewal bill unique per subscription period (MON-10).
     */
    public static function renewalPeriodKey(int $subscriptionId, string $dueDate): string
    {
        return 'renewal:'.$subscriptionId.':'.substr($dueDate, 0, 10);
    }

    /**
     * The key that allows one plan-change bill per pending subscription (MON-10).
     */
    public static function planChangeKey(int $subscriptionId): string
    {
        return 'plan_change:'.$subscriptionId;
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SellerSubscription::class, 'subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SellerPayment::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
