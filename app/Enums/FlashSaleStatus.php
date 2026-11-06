<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FlashSaleStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Enrolling = 'enrolling';
    case Reviewing = 'reviewing';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Enrolling => 'Registration Open',
            self::Reviewing => 'In Review',
            self::Scheduled => 'Scheduled',
            self::Active => 'Live now',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getLabel(): ?string
    {
        return $this->label();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Enrolling => 'info',
            self::Reviewing => 'warning',
            self::Scheduled => 'primary',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Ended => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
