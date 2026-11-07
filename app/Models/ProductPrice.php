<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::creating(function (ProductPrice $price): void {
            if (empty($price->sales_channel_id)) {
                $price->sales_channel_id = $price->product?->sales_channel_id
                    ?? SalesChannel::where('slug', SalesChannel::RETAIL)->value('id')
                    ?? SalesChannel::value('id')
                    ?? SalesChannel::create(['name' => 'Retail', 'slug' => 'retail', 'is_active' => true])->id;
            }
        });
    }

    protected $fillable = [
        'product_id',
        'variation_id',
        'sales_channel_id',
        'min_quantity',
        'max_quantity',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function scopeForRetail(Builder $query): Builder
    {
        return $query->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::RETAIL));
    }

    public function scopeForWholesale(Builder $query): Builder
    {
        return $query->whereHas('salesChannel', fn ($q) => $q->where('slug', SalesChannel::WHOLESALE));
    }
}
