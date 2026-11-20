<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * AUD-8: turns an audit entry's before/after values into short plain lines, such as "Price: ৳100.00 → ৳90.00",
 * for people who should not need to read database columns.
 */
class AuditChangeSummary
{
    /** Bookkeeping fields nobody needs to read. */
    private const SKIPPED_KEYS = ['id', 'created_at', 'updated_at', 'deleted_at', 'seller_id', 'remember_token', 'password'];

    private const REDACTED = '[REDACTED]';

    /**
     * @return list<string>
     */
    public function lines(AuditLog $log): array
    {
        $old = (array) ($log->old_values ?? []);
        $new = (array) ($log->new_values ?? []);
        $isChange = $old !== [] && $new !== [];
        $values = $new !== [] ? $new : $old;

        $lines = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::SKIPPED_KEYS, true)) {
                continue;
            }

            $label = $this->label($key);
            $isReference = str_ends_with($key, '_id');

            if (! $isChange) {
                if ($isReference || $value === self::REDACTED || $value === null) {
                    continue;
                }

                $lines[] = "{$label}: ".$this->format($key, $value);

                continue;
            }

            $before = $old[$key] ?? null;

            // Hidden values look the same before and after, but the entry says they changed
            if ($value === self::REDACTED || $before === self::REDACTED) {
                $lines[] = "{$label}: changed (hidden)";
            } elseif ($before === $value) {
                continue;
            } elseif ($isReference) {
                $lines[] = "{$label}: changed";
            } else {
                $lines[] = "{$label}: ".$this->format($key, $before).' → '.$this->format($key, $value);
            }
        }

        return $lines;
    }

    private function label(string $key): string
    {
        $key = (string) preg_replace('/^is_/', '', $key);
        $key = (string) preg_replace('/_id$/', '', $key);

        return Str::ucfirst(Str::lower(Str::headline($key)));
    }

    private function format(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value) || (str_starts_with($key, 'is_') && in_array($value, [0, 1, '0', '1'], true))) {
            return (bool) $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return array_is_list($value) && array_filter($value, fn (mixed $item): bool => ! is_scalar($item)) === []
                ? implode(', ', array_map('strval', $value))
                : 'updated';
        }

        if (is_numeric($value) && $this->isMoney($key)) {
            return '৳'.number_format((float) $value, 2);
        }

        return Str::limit((string) $value, 120);
    }

    private function isMoney(string $key): bool
    {
        return (bool) preg_match('/(^|_)(price|amount|balance|total|subtotal|fee|cost)$/', $key);
    }
}
