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
    public function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if ($this->isBlacklisted($lowerKey)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    protected function isBlacklisted(string $key): bool
    {
        foreach ($this->blacklistedKeys as $blacklisted) {
            if ($key === $blacklisted || str_ends_with($key, '_'.$blacklisted)) {
                return true;
            }
        }

        return false;
    }
}
