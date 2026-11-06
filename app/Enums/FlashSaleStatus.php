<?php

declare(strict_types=1);

namespace App\Enums;

enum FlashSaleStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Active => 'Live now',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
            self::Cancelled => 'Cancelled',
        };
    }
}
