<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariation extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'buy_price',
        'stock_quantity',
        'is_active',
    ];

    protected $hidden = [
        'buy_price',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'variation_attribute_values',
            'variation_id',
            'attribute_value_id'
        );
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'variation_id');
    }
}
