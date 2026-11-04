<?php

declare(strict_types=1);

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\DTOs\SmsResponse;
use App\Services\Sms\Support\PhoneNormalizer;

class MockDriver implements SmsGatewayInterface
{
    /**
     * @var array<int, array{to: string, message: string, purpose: ?string}>
     */
    public array $sentMessages = [];

    public bool $shouldSucceed = true;

    public float $mockBalance = 500.00;

    public function send(string $to, string $message, ?string $purpose = null): SmsResponse
    {
        $normalized = PhoneNormalizer::isValid($to) ? PhoneNormalizer::normalize($to) : $to;

        $this->sentMessages[] = [
            'to' => $normalized,
            'message' => $message,
            'purpose' => $purpose,
        ];

        if (! $this->shouldSucceed) {
            return new SmsResponse(
                isSuccessful: false,
                responseCode: '1007',
                messageId: null,
                errorMessage: 'Mock insufficient balance',
                rawPayload: ['mock' => true]
            );
        }

        return new SmsResponse(
            isSuccessful: true,
            responseCode: '202',
            messageId: 'MOCK_'.uniqid(),
            errorMessage: null,
            rawPayload: ['mock' => true, 'to' => $normalized]
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
        return $this->mockBalance;
    }

    public function hasSentTo(string $phone): bool
    {
        $normalized = PhoneNormalizer::isValid($phone) ? PhoneNormalizer::normalize($phone) : $phone;
        foreach ($this->sentMessages as $msg) {
            if ($msg['to'] === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->sentMessages = [];
        $this->shouldSucceed = true;
    }
}
