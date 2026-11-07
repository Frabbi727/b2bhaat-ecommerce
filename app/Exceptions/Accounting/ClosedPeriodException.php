<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

class ClosedPeriodException extends DomainException
{
    public static function forPeriod(string $periodName): self
    {
        return new self("Cannot post journal entry: Accounting period '{$periodName}' is closed.");
    }
}
