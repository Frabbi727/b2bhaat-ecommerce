<?php

declare(strict_types=1);

namespace App\Services\Formatting;

/**
 * UX-1: the one way money is written on screen, for example ৳1,234.50. SMS texts keep "BDT" because ৳ would make
 * every message a Unicode SMS, and CSV headers keep "(BDT)" for spreadsheets.
 *
 * PAY-5: totals are added in whole poisha, because adding taka as floats drifts (0.70 + 3 × 0.10 is
 * 0.9999999999999999, which falls short of a ৳1.00 minimum).
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

    public static function poisha(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function fromPoisha(int $poisha): float
    {
        return round($poisha / 100, 2);
    }

    /**
     * Sum of unit price × quantity over cart or order lines, exact to the poisha.
     *
     * @param  iterable<array{unit_price: int|float|string, quantity: int|string}>  $lines
     */
    public static function lineTotal(iterable $lines): float
    {
        $poisha = 0;
        foreach ($lines as $line) {
            $poisha += self::poisha($line['unit_price']) * (int) $line['quantity'];
        }

        return self::fromPoisha($poisha);
    }
}
