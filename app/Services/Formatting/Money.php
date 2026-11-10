<?php

declare(strict_types=1);

namespace App\Services\Formatting;

/**
 * UX-1: the one way money is written on screen, for example ৳1,234.50. SMS texts keep "BDT" because ৳ would make
 * every message a Unicode SMS, and CSV headers keep "(BDT)" for spreadsheets.
 */
class Money
{
    public static function taka(int|float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = round((float) $amount, 2);

        return ($value < 0 ? '-' : '').'৳'.number_format(abs($value), 2);
    }
}
