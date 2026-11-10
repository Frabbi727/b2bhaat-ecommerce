<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

/**
 * ACC-21: a journal was posted again under an existing idempotency key, but with different accounts or amounts.
 */
class IdempotencyConflictException extends DomainException
{
    public static function forKey(string $key, string $journalNumber): self
    {
        return new self("Journal key {$key} was already used by {$journalNumber} with different accounts or amounts. Nothing was posted; check which event should own this key.");
    }
}
