<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\Otp;
use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Support\PhoneNormalizer;
use Carbon\Carbon;

class OtpService
{
    public function __construct(
        protected ?SmsGatewayInterface $sms = null
    ) {}

    /**
     * Generate, hash, and dispatch a secure OTP code.
     *
     * @return array{success: bool, code?: string, expires_at?: Carbon, error?: string, seconds_remaining?: int}
     */
    public function sendOtp(string $phone, string $purpose = 'verification'): array
    {
        $normalized = PhoneNormalizer::normalize($phone);

        // 1. Check cooldown period (default 60 seconds)
        $cooldown = (int) SmsSetting::get('otp_cooldown_seconds', config('sms.otp.cooldown_seconds', 60));
        $recent = Otp::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->where('created_at', '>', Carbon::now()->subSeconds($cooldown))
            ->latest('id')
            ->first();

        if ($recent) {
            $secondsRemaining = $cooldown - Carbon::now()->diffInSeconds($recent->created_at);

            return [
                'success' => false,
                'error' => 'COOLDOWN_ACTIVE',
                'seconds_remaining' => (int) max(1, $secondsRemaining),
            ];
        }

        // 2. Invalidate previous active unverified OTPs for this phone + purpose
        Otp::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();

        // 3. Generate secure random numeric OTP
        $length = (int) SmsSetting::get('otp_length', config('sms.otp.length', 6));
        $expiryMinutes = (int) SmsSetting::get('otp_expiry_minutes', config('sms.otp.expiry_minutes', 5));
        $maxAttempts = (int) SmsSetting::get('otp_max_attempts', config('sms.otp.max_attempts', 5));

        $min = 10 ** ($length - 1);
        $max = (10 ** $length) - 1;
        $code = (string) random_int($min, $max);
        $codeHash = hash('sha256', $code);

        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);

        Otp::create([
            'phone' => $normalized,
            'code_hash' => $codeHash,
            'purpose' => $purpose,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => $expiresAt,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        $message = "Your B2Bhaat OTP is {$code}. Valid for {$expiryMinutes} minutes. Do not share this code with anyone.";

        SendSmsJob::dispatch($normalized, $message, "otp_{$purpose}", bypassDeduplication: true);

        return [
            'success' => true,
            'code' => $code,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify an OTP submitted by the user.
     *
     * @return array{success: bool, error?: string, attempts_left?: int}
     */
    public function verify(string $phone, string $code, string $purpose = 'verification'): array
    {
        $normalized = PhoneNormalizer::normalize($phone);

        $otp = Otp::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return [
                'success' => false,
                'error' => 'NOT_FOUND',
            ];
        }

        if ($otp->attempts >= $otp->max_attempts) {
            return [
                'success' => false,
                'error' => 'MAX_ATTEMPTS_EXCEEDED',
            ];
        }

        if ($otp->expires_at->isPast()) {
            return [
                'success' => false,
                'error' => 'EXPIRED',
            ];
        }

        $submittedHash = hash('sha256', $code);
        if (! hash_equals($otp->code_hash, $submittedHash)) {
            $otp->increment('attempts');
            $attemptsLeft = max(0, $otp->max_attempts - ($otp->attempts + 1));

            return [
                'success' => false,
                'error' => 'INVALID_CODE',
                'attempts_left' => $attemptsLeft,
            ];
        }

        $otp->update([
            'verified_at' => Carbon::now(),
        ]);

        return [
            'success' => true,
        ];
    }
}
