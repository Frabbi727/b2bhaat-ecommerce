<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Raised when a seller tries to make products visible beyond their plan's listing limit or while the
 * store is suspended. The message is written for the seller.
 */
class ListingActivationRefusedException extends DomainException {}
