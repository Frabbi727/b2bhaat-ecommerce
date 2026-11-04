<?php

declare(strict_types=1);

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\DTOs\SmsResponse;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

class LogDriver implements SmsGatewayInterface
{
    public function send(string $to, string $message, ?string $purpose = null): SmsResponse
    {
        $normalized = PhoneNormalizer::isValid($to) ? PhoneNormalizer::normalize($to) : $to;

        Log::info('[SMS LOG DRIVER] Outgoing SMS', [
            'to' => $normalized,
            'message' => $message,
            'purpose' => $purpose,
        ]);

        return new SmsResponse(
            isSuccessful: true,
            responseCode: '202',
            messageId: 'LOG_'.uniqid(),
            errorMessage: null,
            rawPayload: ['driver' => 'log', 'to' => $normalized]
        );
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<string, SmsResponse>
     */
    public function sendBulk(array $recipients, string $message): array
    {
        $results = [];
        foreach ($recipients as $recipient) {
            $results[$recipient] = $this->send($recipient, $message);
        }

        return $results;
    }

    public function checkBalance(): float|string
    {
        return 9999.00;
    }
}
