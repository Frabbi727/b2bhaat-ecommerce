<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * INV-4: raised when an option (variation) that appears on an order is deleted. The message is written for the seller.
 */
class VariationInUseException extends DomainException {}
