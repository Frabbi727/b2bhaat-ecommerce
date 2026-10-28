<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttributeValue extends Model
{
    protected $fillable = ['attribute_id', 'value', 'color_code'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function variations(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariation::class,
            'variation_attribute_values',
            'attribute_value_id',
            'variation_id'
        );
    }
}
