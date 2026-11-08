<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Raised when an approval action cannot proceed because the product or request is in the wrong state or has
 * changed since the reviewer opened it. The message is written in plain language for the person who sees it.
 */
class ApprovalConflictException extends DomainException {}
