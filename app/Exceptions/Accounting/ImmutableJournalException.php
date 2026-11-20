<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

class ImmutableJournalException extends DomainException
{
    public static function cannotModify(string $journalNumber): self
    {
        return new self("Journal entry '{$journalNumber}' is already posted and immutable. Use reversal instead.");
    }
}
