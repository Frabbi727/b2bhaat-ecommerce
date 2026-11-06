<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerSuspensionEvent extends Model
{
    use HasFactory;

    public const TRIGGER_AUTOMATED_BILLING = 'automated_billing';

    public const TRIGGER_ADMIN_MANUAL = 'admin_manual';

    public const TRIGGER_POLICY_VIOLATION = 'policy_violation';

    protected $fillable = [
        'seller_id',
        'trigger_type',
        'snapshot_active_product_ids',
        'restored_product_ids',
        'suspended_at',
        'restored_at',
        'reason',
        'action_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_active_product_ids' => 'array',
            'restored_product_ids' => 'array',
            'suspended_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function actionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by_user_id');
    }
}
