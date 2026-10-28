<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FulfillmentStatus extends Model
{
    protected $fillable = ['name', 'slug', 'badge_color', 'sort_order'];

    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }
}
