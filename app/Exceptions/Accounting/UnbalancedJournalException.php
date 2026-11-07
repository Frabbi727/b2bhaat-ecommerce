<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

class UnbalancedJournalException extends DomainException
{
    public static function forAmounts(float $debit, float $credit): self
    {
        return new self("Unbalanced journal entry: Total Debit (৳{$debit}) does not equal Total Credit (৳{$credit}).");
    }
}
