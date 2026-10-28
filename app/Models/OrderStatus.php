<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderStatus extends Model
{
    protected $fillable = ['name', 'slug', 'badge_color', 'sort_order'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
