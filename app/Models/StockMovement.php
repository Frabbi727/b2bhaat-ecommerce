<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change to a variation's stock (AR-08). Written by ProductVariationStockObserver; never edited.
 *
 * @property int $quantity_change
 * @property int $quantity_after
 * @property string $reason
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const REASON_OPENING = 'opening_balance';

    public const REASON_ORDER_PLACED = 'order_placed';

    public const REASON_ORDER_CANCELLED = 'order_cancelled';

    public const REASON_RETURNED = 'returned';

    public const REASON_ADJUSTMENT = 'adjustment';

    public const REASON_BULK_UPDATE = 'bulk_update';

    public const REASON_IMPORT = 'import';

    public const REASON_PRODUCT_EDIT = 'product_edit';

    /** Units sent again in a free replacement parcel (WA-ORD-03) */
    public const REASON_REPLACEMENT = 'replacement';

    /** @var array<string, string> reason => what the seller and admin see */
    public const REASON_LABELS = [
        self::REASON_OPENING => 'Opening stock',
        self::REASON_ORDER_PLACED => 'Order placed',
        self::REASON_ORDER_CANCELLED => 'Order cancelled',
        self::REASON_RETURNED => 'Returned by buyer or courier',
        self::REASON_ADJUSTMENT => 'Stock adjustment',
        self::REASON_BULK_UPDATE => 'Stock sheet upload',
        self::REASON_IMPORT => 'Product import',
        self::REASON_PRODUCT_EDIT => 'Product edited',
        self::REASON_REPLACEMENT => 'Replacement sent',
    ];

    protected $fillable = [
        'product_variation_id',
        'sku',
        'quantity_change',
        'quantity_after',
        'reason',
        'reference_type',
        'reference_id',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }
}
