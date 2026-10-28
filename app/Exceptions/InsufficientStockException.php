<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(
        string $message = 'One or more items do not have sufficient stock available.',
        public ?int $variationId = null,
        public ?int $requestedQuantity = null,
        public ?int $availableQuantity = null,
    ) {
        parent::__construct($message);
    }
}
