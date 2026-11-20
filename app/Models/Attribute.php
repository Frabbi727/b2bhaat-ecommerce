<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use Auditable;

    protected $fillable = ['name', 'slug'];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
