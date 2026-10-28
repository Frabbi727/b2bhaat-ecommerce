<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RESOLVED]);
    }
}
