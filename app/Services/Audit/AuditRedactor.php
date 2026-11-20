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
     * AUD-7: payment TrxIDs keep their last four characters, so an entry can still be matched to a bKash or Nagad
     * statement without storing the full reference.
     *
     * @var array<string>
     */
    protected array $maskedKeys = [
        'transaction_id',
        'trx_id',
        'trx_key',
        'transaction_reference',
    ];

    /** AUD-7: path segments that carry a secret link token. */
    protected const TOKEN_PATHS = ['track', 'invoice'];

    /** AUD-7: query parameters that carry a secret. */
    protected const TOKEN_QUERY_KEYS = ['token', 'signature', 'invoice_token', 'tracking_token'];

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
            } elseif ($this->isMaskedKey($stringKey) && is_scalar($value) && $value !== '') {
                $redacted[$key] = $this->mask((string) $value);
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value, $maxDepth, $depth + 1);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * AUD-7: removes secret link tokens from a URL: `/track/{token}`, `/invoice/{token}` and token-like query
     * parameters.
     */
    public function redactUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $paths = implode('|', array_map(fn (string $path): string => preg_quote($path, '#'), self::TOKEN_PATHS));
        $url = (string) preg_replace('#/('.$paths.')/(?!view(?:[/?\#]|$))[^/?\#]+#i', '/$1/[REDACTED]', $url);

        $keys = implode('|', array_map(fn (string $key): string => preg_quote($key, '#'), self::TOKEN_QUERY_KEYS));

        return (string) preg_replace('#([?&](?:'.$keys.')=)[^&\#]*#i', '$1[REDACTED]', $url);
    }

    /**
     * AUD-7: masks TrxIDs written into free text, such as "paid via bKash (TrxID: 9AB7XK2Q51)".
     */
    public function redactText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return (string) preg_replace_callback(
            '/(Trx\s*ID\s*[:#]?\s*)([A-Za-z0-9-]{5,})/i',
            fn (array $match): string => $match[1].$this->mask($match[2]),
            $text,
        );
    }

    public function mask(string $value): string
    {
        return '••••'.(mb_strlen($value) > 4 ? mb_substr($value, -4) : '');
    }

    protected function isMaskedKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        foreach ($this->maskedKeys as $masked) {
            if (str_ends_with($normalized, strtolower(str_replace(['-', '_'], '', $masked)))) {
                return true;
            }
        }

        return false;
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->isBlacklisted($key);
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
