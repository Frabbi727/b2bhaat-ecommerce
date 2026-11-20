<?php

declare(strict_types=1);

namespace App\Enums;

enum FlashSaleDiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage off',
            self::FixedAmount => 'Taka off',
            self::FixedPrice => 'Fixed sale price',
        };
    }
}
