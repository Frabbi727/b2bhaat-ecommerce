<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 180];

    public function __construct(
        public string $phone,
        public string $message,
        public string $purpose = 'general',
        public bool $bypassDeduplication = false
    ) {}

    public function handle(SmsGatewayInterface $sms): void
    {
        // 1. Check if SMS is globally enabled
        $isEnabled = SmsSetting::get('sms_enabled', config('sms.enabled', true));
        if ($isEnabled === 'false' || $isEnabled === false) {
            $normalizedPhone = PhoneNormalizer::isValid($this->phone)
                ? PhoneNormalizer::normalize($this->phone)
                : $this->phone;

            SmsLog::create([
                'recipient_phone' => $normalizedPhone,
                'message' => $this->maskMessage($this->message),
                'purpose' => $this->purpose,
                'driver' => config('sms.default', 'bulksmsbd'),
                'status' => 'skipped',
                'error_message' => 'SMS sending is globally disabled in settings.',
            ]);

            return;
        }

        $normalizedPhone = PhoneNormalizer::normalize($this->phone);

        // 2. Deduplication guard (60 seconds)
        $lockKey = 'sms_lock:'.$normalizedPhone.':'.md5($this->message);
        // A retry (attempt 2+) is the same message, not a duplicate (PERF-4)
        if (! $this->bypassDeduplication && $this->attempts() <= 1 && ! Cache::add($lockKey, true, 60)) {
            return; // Duplicate prevented
        }

        // 3. Create initial pending log
        $log = SmsLog::create([
            'recipient_phone' => $normalizedPhone,
            'message' => $this->maskMessage($this->message),
            'purpose' => $this->purpose,
            'driver' => config('sms.default', 'bulksmsbd'),
            'status' => 'pending',
            'retries_count' => $this->attempts(),
        ]);

        try {
            $response = $sms->send($normalizedPhone, $this->message, $this->purpose);
        } catch (\Throwable $e) {
            // PERF-4: a gateway error must not leave the log row "pending"
            $log->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $log->update([
            'status' => $response->isSuccessful() ? 'sent' : 'failed',
            'provider_message_id' => $response->messageId(),
            'provider_response_code' => $response->responseCode(),
            'response_payload' => $response->rawPayload(),
            'error_message' => $response->errorMessage(),
            'sent_at' => $response->isSuccessful() ? now() : null,
        ]);

        if (! $response->isSuccessful() && $response->responseCode() !== '1001') {
            throw new RuntimeException('SMS dispatch failed: '.($response->errorMessage() ?? 'Unknown provider error'));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('SMS gave up after all retries: '.$e->getMessage(), ['purpose' => $this->purpose]);
    }

    /**
     * Mask 4-8 digit numeric codes (such as OTPs) from stored database audit logs.
     */
    protected function maskMessage(string $msg): string
    {
        return (string) preg_replace('/\b(\d{4,8})\b/', '******', $msg);
    }
}
