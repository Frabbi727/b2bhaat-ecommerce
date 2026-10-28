<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
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
