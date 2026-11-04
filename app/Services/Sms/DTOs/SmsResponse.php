<?php

declare(strict_types=1);

namespace App\Services\Sms\DTOs;

class SmsResponse
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        protected bool $isSuccessful,
        protected string $responseCode,
        protected ?string $messageId = null,
        protected ?string $errorMessage = null,
        protected array $rawPayload = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    public function responseCode(): string
    {
        return $this->responseCode;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawPayload(): array
    {
        return $this->rawPayload;
    }
}
