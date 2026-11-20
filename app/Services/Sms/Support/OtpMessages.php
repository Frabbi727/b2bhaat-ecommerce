<?php

declare(strict_types=1);

namespace App\Services\Sms\Support;

/**
 * Plain words shown to a shopper when an SMS code could not be sent or was not accepted.
 */
final class OtpMessages
{
    /**
     * @param  array{success: bool, error?: string, seconds_remaining?: int}  $result
     */
    public static function sendFailure(array $result): string
    {
        return match ($result['error'] ?? null) {
            'COOLDOWN_ACTIVE' => "Please wait {$result['seconds_remaining']} seconds before requesting a new code.",
            'RATE_LIMITED' => 'Too many codes have been requested. Please try again later.',
            default => 'We could not send the code. Please try again.',
        };
    }

    /**
     * @param  array{success: bool, error?: string, seconds_remaining?: int}  $result
     */
    public static function verifyFailure(array $result): string
    {
        return match ($result['error'] ?? null) {
            'LOCKED' => self::locked($result),
            'EXPIRED' => 'The code has expired. Please request a new code.',
            'MAX_ATTEMPTS_EXCEEDED' => 'Too many wrong tries. Please request a new code.',
            default => 'That code is not right. Please check the SMS and try again.',
        };
    }

    /**
     * @param  array{success: bool, error?: string, seconds_remaining?: int}  $result
     */
    public static function locked(array $result): string
    {
        $minutes = (int) max(1, ceil(($result['seconds_remaining'] ?? 60) / 60));

        return "Too many wrong codes for this number. Please try again in {$minutes} minute(s).";
    }
}
