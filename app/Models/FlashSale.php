<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlashSaleStatus;
use App\Services\Promotions\FlashSalePricing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'banner_path',
        'terms',
        'status',
        'starts_at',
        'ends_at',
        'is_featured',
        'display_priority',
        'paused_at',
        'ended_at',
        'created_by',
    ];

    /**
     * Any change to a campaign can change prices, so the in-request offer memo is dropped.
     */
    protected static function booted(): void
    {
        static::saved(static function (): void {
            FlashSalePricing::flushResolved();
        });
        static::deleted(static function (): void {
            FlashSalePricing::flushResolved();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => FlashSaleStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_featured' => 'boolean',
            'display_priority' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'flash_sale_products')->withTimestamps();
    }

    /**
     * @return HasMany<FlashSaleProduct, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FlashSaleProduct::class);
    }

    /**
     * The status the system acts on at the given moment. Stored `scheduled`
     * and `active` are read from the clock; the other stored states are final.
     */
    public function effectiveStatus(?CarbonInterface $at = null): FlashSaleStatus
    {
        $at ??= now();

        if (! in_array($this->status, [FlashSaleStatus::Scheduled, FlashSaleStatus::Active], true)) {
            return $this->status;
        }

        if ($at->lt($this->starts_at)) {
            return FlashSaleStatus::Scheduled;
        }

        if ($at->lt($this->ends_at)) {
            return FlashSaleStatus::Active;
        }

        return FlashSaleStatus::Ended;
    }

    public function isLive(?CarbonInterface $at = null): bool
    {
        return $this->effectiveStatus($at) === FlashSaleStatus::Active;
    }

    /**
     * @param  Builder<FlashSale>  $query
     * @return Builder<FlashSale>
     */
    public function scopeLive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->whereIn('status', $this->publishedStatuses())
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /**
     * @param  Builder<FlashSale>  $query
     * @return Builder<FlashSale>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', $this->publishedStatuses())
            ->where('starts_at', '>', now());
    }

    /**
     * @return list<string>
     */
    private function publishedStatuses(): array
    {
        return [FlashSaleStatus::Scheduled->value, FlashSaleStatus::Active->value];
    }
}
