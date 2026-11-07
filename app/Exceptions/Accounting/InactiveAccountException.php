<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

class InactiveAccountException extends DomainException
{
    public static function forAccount(string $identifier): self
    {
        return new self("Cannot post to inactive or deactivated account: '{$identifier}'.");
    }
}
