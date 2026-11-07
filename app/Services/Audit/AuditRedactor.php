<?php

declare(strict_types=1);

namespace App\Services\Audit;

class AuditRedactor
{
    /** @var array<string> */
    protected array $blacklistedKeys = [
        'password',
        'password_confirmation',
        'remember_token',
        'handover_code',
        'code_hash',
        'otp',
        'otp_code',
        'api_key',
        'secret',
        'secret_key',
        'private_key',
        'encryption_key',
        'pin',
        'sms_gateway_password',
        'card_number',
        'cvv',
        'auth_token',
        'token',
    ];

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public function redact(?array $data, int $maxDepth = 20, int $depth = 0): ?array
    {
        if ($data === null) {
            return null;
        }

        if ($depth >= $maxDepth) {
            return ['[MAX_DEPTH_REACHED]'];
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            $stringKey = (string) $key;
            if ($this->isBlacklisted($stringKey)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value, $maxDepth, $depth + 1);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    protected function isBlacklisted(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        foreach ($this->blacklistedKeys as $blacklisted) {
            $normalizedBlacklisted = strtolower(str_replace(['-', '_'], '', $blacklisted));
            if ($normalized === $normalizedBlacklisted || str_ends_with($normalized, $normalizedBlacklisted)) {
                return true;
            }
        }

        return false;
    }
}
