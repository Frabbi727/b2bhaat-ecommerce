<?php

namespace App\Models;

use App\Services\Inventory\ProductCodeGenerator;
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

    /**
     * Variations added without a SKU get the next one in the product's sequence (e.g. `BEA-000042-03`).
     */
    protected static function booted(): void
    {
        static::creating(function (ProductVariation $variation): void {
            if (blank($variation->sku) && $variation->product_id) {
                $variation->sku = ProductCodeGenerator::variationSku($variation->product ?? Product::findOrFail($variation->product_id));
            }
        });
    }

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
