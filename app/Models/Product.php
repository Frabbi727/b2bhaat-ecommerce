<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'seller_id',
        'category_id',
        'brand_id',
        'sales_channel_id',
        'unit_id',
        'title',
        'slug',
        'short_description',
        'full_description',
        'buy_price',
        'has_variations',
        'is_active',
    ];

    protected $hidden = [
        'buy_price',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'decimal:2',
            'has_variations' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany([
            'sort_order' => 'min',
            'id' => 'min',
        ]);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function retailPrice()
    {
        return $this->hasOne(ProductPrice::class)
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
            ->whereNull('variation_id')
            ->orderBy('min_quantity');
    }

    public function wholesalePrice()
    {
        return $this->hasOne(ProductPrice::class)
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::WHOLESALE))
            ->whereNull('variation_id')
            ->orderBy('min_quantity');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel(Builder $query, string $channelSlug): Builder
    {
        return $query->whereHas('salesChannel', function (Builder $q) use ($channelSlug) {
            $q->whereIn('slug', [$channelSlug, SalesChannel::BOTH]);
        });
    }
}
