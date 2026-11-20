<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's proposed price (or channel/category) change for an approved product, held until an admin reviews it.
 * Status and review fields are written only by the approval service, so they are not mass assignable.
 */
class ProductChangeRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected string $auditModule = 'approvals';

    protected $fillable = [
        'product_id',
        'seller_id',
        'proposed_prices',
        'proposed_regular_price',
        'proposed_sales_channel_id',
        'proposed_category_id',
        'base_prices',
        'base_regular_price',
        'base_sales_channel_id',
        'base_category_id',
        'base_fingerprint',
        'seller_note',
        'submitted_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed_prices' => 'array',
            'base_prices' => 'array',
            'proposed_regular_price' => 'decimal:2',
            'base_regular_price' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
