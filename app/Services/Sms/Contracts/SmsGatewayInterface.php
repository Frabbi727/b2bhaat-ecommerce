<?php

declare(strict_types=1);

namespace App\Services\Sms\Contracts;

use App\Services\Sms\DTOs\SmsResponse;

interface SmsGatewayInterface
{
    /**
     * Send an SMS to a single recipient.
     */
    public function send(string $to, string $message, ?string $purpose = null): SmsResponse;

    /**
     * Send an SMS to multiple recipients.
     *
     * @param  array<int, string>  $recipients
     * @return array<string, SmsResponse>
     */
    public function sendBulk(array $recipients, string $message): array;

    /**
     * Query remaining balance from the provider.
     */
    public function checkBalance(): float|string;
}
