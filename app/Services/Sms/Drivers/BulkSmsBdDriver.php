<?php

declare(strict_types=1);

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\DTOs\SmsResponse;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Throwable;

class BulkSmsBdDriver implements SmsGatewayInterface
{
    public function __construct(
        protected string $apiKey,
        protected string $senderId,
        protected string $baseUrl = 'http://bulksmsbd.net/api/smsapi',
        protected string $balanceUrl = 'http://bulksmsbd.net/api/getBalanceApi'
    ) {}

    public function send(string $to, string $message, ?string $purpose = null): SmsResponse
    {
        try {
            $normalizedPhone = PhoneNormalizer::normalize($to);

            $response = Http::timeout(10)->asForm()->post($this->baseUrl, [
                'api_key' => $this->apiKey,
                'senderid' => $this->senderId,
                'type' => 'text',
                'number' => $normalizedPhone,
                'message' => $message,
            ]);

            $json = $response->json();
            if (! is_array($json)) {
                $rawBody = $response->body();
                $json = json_decode($rawBody, true) ?? ['raw_response' => $rawBody];
            }

            $code = (string) ($json['response_code'] ?? $response->status());
            $isSuccess = ($code === '202');

            $messageId = isset($json['message_id']) ? (string) $json['message_id'] : null;
            $errorMessage = $isSuccess ? null : ($json['error_message'] ?? $json['message'] ?? 'SMS dispatch error code: '.$code);

            return new SmsResponse(
                isSuccessful: $isSuccess,
                responseCode: $code,
                messageId: $messageId,
                errorMessage: $errorMessage,
                rawPayload: $json
            );
        } catch (Throwable $e) {
            return new SmsResponse(
                isSuccessful: false,
                responseCode: '500',
                messageId: null,
                errorMessage: $e->getMessage(),
                rawPayload: ['exception' => $e->getMessage()]
            );
        }
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
        try {
            $response = Http::timeout(5)->get($this->balanceUrl, [
                'api_key' => $this->apiKey,
            ]);

            $json = $response->json();
            if (! is_array($json)) {
                $json = json_decode($response->body(), true) ?? [];
            }

            if (isset($json['balance'])) {
                return (float) $json['balance'];
            }

            return $json['error_message'] ?? 'Unable to retrieve balance';
        } catch (Throwable $e) {
            return 'Unavailable: '.$e->getMessage();
        }
    }
}
