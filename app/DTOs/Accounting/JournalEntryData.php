<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use App\Exceptions\Accounting\UnbalancedJournalException;

readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>  $lines
     */
    public function __construct(
        public string $entryDate,
        public string $financialEvent,
        public string $idempotencyKey,
        public string $description,
        public array $lines,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?int $createdByUserId = null,
        public ?array $metadata = null,
    ) {}

    public function totalDebit(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += $line->debit;
        }

        return round($sum, 2);
    }

    public function totalCredit(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += $line->credit;
        }

        return round($sum, 2);
    }

    public function isBalanced(): bool
    {
        return count($this->lines) >= 2
            && $this->totalDebit() > 0.00
            && bccomp(sprintf('%.2f', $this->totalDebit()), sprintf('%.2f', $this->totalCredit()), 2) === 0;
    }

    public function validate(): void
    {
        if (! $this->isBalanced()) {
            throw UnbalancedJournalException::forAmounts($this->totalDebit(), $this->totalCredit());
        }
    }
}
