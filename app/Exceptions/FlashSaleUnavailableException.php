<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * A cart line asks for a flash price the campaign can no longer give. The message is shown to the buyer as is.
 */
class FlashSaleUnavailableException extends Exception
{
    public function __construct(string $message, public readonly bool $saleEnded = false)
    {
        parent::__construct($message);
    }

    public static function ended(string $title): self
    {
        return new self("The flash sale for {$title} has ended.", saleEnded: true);
    }

    public static function onlyLeft(int $remaining, string $title): self
    {
        return new self("Only {$remaining} left at the flash sale price for {$title}.");
    }

    public static function customerLimit(int $limit, string $title): self
    {
        return new self("You can buy up to {$limit} of {$title} at the flash sale price.");
    }
}
