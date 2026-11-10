<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    use Auditable;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'sub_order_id',
        'customer_id',
        'reason',
        'requested_action',
        'description',
        'image_proof_url',
        'status',
        'seller_note',
        'admin_arbitration_note',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * PAY-4: the items this return covers. None means the whole parcel (every request made before PAY-4).
     *
     * @return HasMany<ReturnRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    /**
     * What is coming back, for sellers and admins: "Nail set × 3, Gel polish × 1", or "Whole parcel".
     */
    public function itemsSummary(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('orderItem.product')->get();

        if ($items->isEmpty()) {
            return 'Whole parcel';
        }

        return $items
            ->map(fn (ReturnRequestItem $item): string => ($item->orderItem?->product?->title ?? 'Item').' × '.$item->quantity)
            ->implode(', ');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RESOLVED]);
    }
}
