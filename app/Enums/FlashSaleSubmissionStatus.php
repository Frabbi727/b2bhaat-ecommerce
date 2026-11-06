<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FlashSaleSubmissionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Pending = 'pending';
    case RevisionRequested = 'revision_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending Approval',
            self::RevisionRequested => 'Needs Revision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Paused => 'Paused',
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
            self::Pending => 'warning',
            self::RevisionRequested => 'amber',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Withdrawn => 'gray',
            self::Paused => 'info',
        };
    }
}
