<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use InvalidArgumentException;

readonly class JournalLineData
{
    public function __construct(
        public ?string $accountSlug = null,
        public ?int $accountId = null,
        public float $debit = 0.00,
        public float $credit = 0.00,
        public ?int $sellerId = null,
        public ?int $customerId = null,
        public ?int $subOrderId = null,
        public ?int $orderId = null,
        public ?string $narration = null,
    ) {
        if ($this->debit < 0 || $this->credit < 0) {
            throw new InvalidArgumentException('Debit and credit amounts cannot be negative.');
        }
    }
}
