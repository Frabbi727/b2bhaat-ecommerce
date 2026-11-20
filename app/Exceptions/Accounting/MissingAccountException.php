<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

/**
 * A journal needs an account the chart of accounts doesn't have (ACC-6). Thrown instead of silently skipping the
 * posting, so the money movement around it rolls back rather than leaving the books short.
 */
class MissingAccountException extends DomainException
{
    /**
     * @param  list<string>  $slugs
     */
    public static function forSlugs(array $slugs): self
    {
        return new self('The books are missing the account(s) '.implode(', ', $slugs).'. Nothing was changed; run the chart-of-accounts seeder or add the account, then try again.');
    }
}
