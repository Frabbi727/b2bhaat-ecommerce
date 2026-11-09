<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    public const IMMUTABLE_MESSAGE = 'Audit records cannot be changed or deleted.';

    /**
     * Audit records are append-only (AUD-2). The model refuses edits and deletes; on PostgreSQL a trigger also refuses
     * them for direct queries, except the routine prune, which runs with `app.audit_prune` switched on.
     */
    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $log->created_at ??= now();
        });

        static::updating(fn (): never => throw new LogicException(self::IMMUTABLE_MESSAGE));
        static::deleting(fn (): never => throw new LogicException(self::IMMUTABLE_MESSAGE));
    }

    protected $fillable = [
        'correlation_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'auth_guard',
        'seller_id',
        'module',
        'action',
        'description',
        'auditable_type',
        'auditable_id',
        'record_label',
        'old_values',
        'new_values',
        'changed_fields',
        'status',
        'failure_reason',
        'is_suspicious',
        'risk_notes',
        'ip_address',
        'user_agent',
        'device_type',
        'browser',
        'os',
        'request_method',
        'request_url',
        'route_name',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'metadata' => 'array',
            'is_suspicious' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        return $query->where('is_suspicious', true);
    }

    public function scopeModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
