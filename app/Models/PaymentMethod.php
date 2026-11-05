<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    /** Gateways that are configured in the database but have no integration yet (owner decision D8). */
    public const NOT_INTEGRATED = ['sslcommerz'];

    /** Test-only gateway; never offered outside local and testing. */
    public const MOCK = 'mock_gateway';

    protected $fillable = ['name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Payment methods a buyer can actually complete: active, integrated, and the mock only in local/testing.
     */
    public function scopeAvailableToBuyers(Builder $query): Builder
    {
        $query->where('is_active', true)->whereNotIn('slug', self::NOT_INTEGRATED);

        if (! app()->environment('local', 'testing')) {
            $query->where('slug', '!=', self::MOCK);
        }

        return $query;
    }
}
