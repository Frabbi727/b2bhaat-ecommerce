<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTrackingLog extends Model
{
    protected $fillable = [
        'sub_order_id',
        'fulfillment_status_id',
        'title',
        'description',
        'hub_location',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    public function fulfillmentStatus(): BelongsTo
    {
        return $this->belongsTo(FulfillmentStatus::class);
    }
}
