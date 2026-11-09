<?php

namespace App\Filament\Admin\Support;

/**
 * One colour per status meaning, used by every admin table and page: amber needs action, blue is moving,
 * green is done, red stopped, grey is neutral.
 */
class StatusColors
{
    public static function order(?string $slug): string
    {
        return match ($slug) {
            'pending' => 'warning',
            'confirmed', 'processing' => 'info',
            'completed' => 'success',
            'cancelled', 'refunded' => 'danger',
            default => 'gray',
        };
    }

    public static function payment(?string $slug): string
    {
        return match ($slug) {
            'paid' => 'success',
            'awaiting_verification' => 'warning',
            'failed', 'refunded' => 'danger',
            'expired' => 'gray',
            default => 'gray',
        };
    }

    public static function fulfillment(?string $slug): string
    {
        return match ($slug) {
            'unfulfilled' => 'warning',
            'processing', 'shipped', 'in_transit', 'out_for_delivery' => 'info',
            'delivered' => 'success',
            'returned', 'cancelled' => 'danger',
            default => 'gray',
        };
    }
}
