<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use DomainException;

/**
 * A courier remittance or gateway transfer can't be recorded as entered (ACC-7). The message is shown to the admin as
 * is, and nothing has been changed.
 */
class SettlementException extends DomainException {}
