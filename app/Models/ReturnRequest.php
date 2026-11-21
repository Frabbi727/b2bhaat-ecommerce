<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Services\Settings\StoreConfigurationService;
use Carbon\Carbon;
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

    /** Roadmap 3.4: the seller rejected it and the buyer asked B2Bhaat to review; an admin decides. */
    public const STATUS_ESCALATED = 'escalated';

    /** Statuses that keep the parcel's escrow on hold. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_ESCALATED];

    /** reason code => what the buyer picked, in the buyer's words (the same choices as the return form) */
    public const REASONS = [
        'defective_item' => 'The item doesn\'t work',
        'damaged_goods' => 'It arrived damaged',
        'incorrect_variation' => 'Wrong item, colour or size',
        'not_as_described' => 'Not as described',
        'other' => 'Something else',
    ];

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
        'escalated_at',
        'escalation_note',
        'escalation_decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_decided_at' => 'datetime',
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

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? str($this->reason)->replace('_', ' ')->ucfirst()->toString();
    }

    public function wantsRefund(): bool
    {
        return $this->requested_action === 'refund';
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isEscalated(): bool
    {
        return $this->status === self::STATUS_ESCALATED;
    }

    /**
     * How long a buyer has to ask B2Bhaat to review a rejected return: the same hours the escrow is held after
     * delivery, counted from the rejection.
     */
    public static function escalationWindowHours(): int
    {
        return app(StoreConfigurationService::class)->getEscrowHoldingPeriodHours();
    }

    /**
     * The seller rejected it, it was never escalated, and the window is still open.
     */
    public function canBeEscalated(): bool
    {
        return $this->status === self::STATUS_REJECTED
            && $this->escalated_at === null
            && $this->resolved_at !== null
            && $this->resolved_at->copy()->addHours(self::escalationWindowHours())->isFuture();
    }

    public function escalationDeadline(): ?Carbon
    {
        return $this->resolved_at?->copy()->addHours(self::escalationWindowHours());
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RESOLVED]);
    }
}
