<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'product_id',
        'seller_id',
        'buyer_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'company_name',
        'target_quantity',
        'target_unit_price',
        'destination_district',
        'specifications',
        'status',
        'seller_quoted_price',
        'seller_notes',
        'valid_until',
        'dispatch_days',
        'rejected_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'target_unit_price' => 'decimal:2',
            'seller_quoted_price' => 'decimal:2',
            'valid_until' => 'datetime',
            'dispatch_days' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && now()->isAfter($this->valid_until);
    }

    public function canBeAccepted(): bool
    {
        return $this->status === 'quoted' && ! $this->isExpired();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
