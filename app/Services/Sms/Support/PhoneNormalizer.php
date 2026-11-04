<?php

declare(strict_types=1);

namespace App\Services\Sms\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    /**
     * Normalize Bangladeshi mobile numbers into the 13-digit standard format (8801[3-9]XXXXXXXX).
     */
    public static function normalize(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // e.g. +88017XXXXXXXX or 88017XXXXXXXX -> 88017XXXXXXXX (13 digits)
        if (str_starts_with($cleaned, '8801') && strlen($cleaned) === 13) {
            $operatorDigit = $cleaned[4];
            if ($operatorDigit >= '3' && $operatorDigit <= '9') {
                return $cleaned;
            }
        }

        // e.g. 017XXXXXXXX (11 digits) -> 88017XXXXXXXX
        if (str_starts_with($cleaned, '01') && strlen($cleaned) === 11) {
            $operatorDigit = $cleaned[2];
            if ($operatorDigit >= '3' && $operatorDigit <= '9') {
                return '88'.$cleaned;
            }
        }

        // e.g. 17XXXXXXXX (10 digits) -> 88017XXXXXXXX
        if (str_starts_with($cleaned, '1') && strlen($cleaned) === 10) {
            $operatorDigit = $cleaned[1];
            if ($operatorDigit >= '3' && $operatorDigit <= '9') {
                return '880'.$cleaned;
            }
        }

        throw new InvalidArgumentException("Invalid Bangladeshi mobile number: {$phone}");
    }

    public static function isValid(string $phone): bool
    {
        try {
            self::normalize($phone);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
