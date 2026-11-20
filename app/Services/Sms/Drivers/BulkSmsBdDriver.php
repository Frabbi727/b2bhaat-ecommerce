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
        protected string $baseUrl = 'https://bulksmsbd.net/api/smsapi',
        protected string $balanceUrl = 'https://bulksmsbd.net/api/getBalanceApi'
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
            $errorMessage = $isSuccess ? null : self::errorText($json['error_message'] ?? $json['message'] ?? null, 'SMS dispatch error code: '.$code);

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

            return self::errorText($json['error_message'] ?? null, 'Unable to retrieve balance');
        } catch (Throwable $e) {
            return 'Unavailable: '.$e->getMessage();
        }
    }

    /**
     * BulkSMSBD sends error_message as text or as a list of texts (e.g. ["The api key field is required."]).
     */
    private static function errorText(mixed $error, string $fallback): string
    {
        if (is_array($error)) {
            $error = implode(' ', array_filter(array_map(fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $error)));
        }

        return is_scalar($error) && (string) $error !== '' ? (string) $error : $fallback;
    }
}
