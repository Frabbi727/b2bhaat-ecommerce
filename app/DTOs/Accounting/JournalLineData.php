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

        if ($this->accountSlug === null && $this->accountId === null) {
            throw new InvalidArgumentException('A journal line must specify either accountSlug or accountId.');
        }

        if ($this->debit > 0 && $this->credit > 0) {
            throw new InvalidArgumentException('A journal line cannot contain both debit and credit amounts.');
        }

        if ($this->debit <= 0.0 && $this->credit <= 0.0) {
            throw new InvalidArgumentException('A journal line must have either a debit or credit amount greater than zero.');
        }
    }
}
