<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SellerEligibilityType: string implements HasLabel
{
    case All = 'all';
    case VerifiedOnly = 'verified_only';
    case MinimumRating = 'minimum_rating';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All Sellers',
            self::VerifiedOnly => 'KYC Verified Sellers Only',
            self::MinimumRating => 'Minimum Rating Required',
        };
    }

    public function getLabel(): ?string
    {
        return $this->label();
    }
}
