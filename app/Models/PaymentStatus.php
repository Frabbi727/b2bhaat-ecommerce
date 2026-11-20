<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentStatus extends Model
{
    /**
     * The buyer's money was received. Refund pending / partially refunded / refunded orders were paid too, and their
     * remaining parcels still ship, hold escrow and record refunds as paid ones (AR-05).
     */
    public const MONEY_RECEIVED = ['paid', 'refund_pending', 'partially_refunded', 'refunded'];

    protected $fillable = ['name', 'slug', 'badge_color', 'is_terminal'];

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
