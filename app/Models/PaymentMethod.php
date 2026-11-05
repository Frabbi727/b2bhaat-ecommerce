<?php

namespace App\Models;

use App\Services\Settings\StoreConfigurationService;
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
    /**
     * Mobile wallets are only offered once the admin has entered the number that receives the money.
     *
     * @var array<string, string> payment method slug => store setting key
     */
    public const RECEIVING_NUMBER_SETTINGS = [
        'bkash' => 'bkash_merchant_number',
        'nagad' => 'nagad_merchant_number',
    ];

    public function scopeAvailableToBuyers(Builder $query): Builder
    {
        $query->where('is_active', true)->whereNotIn('slug', self::NOT_INTEGRATED);

        if (! app()->environment('local', 'testing')) {
            $query->where('slug', '!=', self::MOCK);
        }

        foreach (self::RECEIVING_NUMBER_SETTINGS as $slug => $settingKey) {
            if (self::receivingNumberFor($slug) === null) {
                $query->where('slug', '!=', $slug);
            }
        }

        return $query;
    }

    /**
     * The admin-configured number a mobile-wallet payment must be sent to, or null when none is set.
     */
    public static function receivingNumberFor(string $slug): ?string
    {
        $settingKey = self::RECEIVING_NUMBER_SETTINGS[$slug] ?? null;
        if ($settingKey === null) {
            return null;
        }

        $number = trim((string) app(StoreConfigurationService::class)->get($settingKey, ''));

        return $number === '' ? null : $number;
    }
}
