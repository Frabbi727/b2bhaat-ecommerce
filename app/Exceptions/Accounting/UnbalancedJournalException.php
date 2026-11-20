<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

class UnbalancedJournalException extends DomainException
{
    public static function forAmounts(float $debit, float $credit): self
    {
        $debitFormatted = sprintf('৳%.2f', $debit);
        $creditFormatted = sprintf('৳%.2f', $credit);

        return new self("Unbalanced journal entry: Total Debit ({$debitFormatted}) does not equal Total Credit ({$creditFormatted}).");
    }
}
