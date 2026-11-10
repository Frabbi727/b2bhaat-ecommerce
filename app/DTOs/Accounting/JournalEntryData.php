<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use App\Exceptions\Accounting\UnbalancedJournalException;
use InvalidArgumentException;

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
    ) {
        foreach ($this->lines as $line) {
            if (! $line instanceof JournalLineData) {
                throw new InvalidArgumentException('All lines in JournalEntryData must be instances of JournalLineData.');
            }
        }
    }

    /**
     * ACC-14: today's date in the business timezone (Dhaka), so a payment at 01:30 on the 1st is booked on the 1st
     * whatever timezone the server stores times in.
     */
    public static function businessDate(): string
    {
        return now()->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))->toDateString();
    }

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
        if (count($this->lines) < 2) {
            throw new UnbalancedJournalException('Journal entry must have at least 2 lines.');
        }

        if ($this->totalDebit() <= 0.00) {
            throw new UnbalancedJournalException('Journal entry total debit must be greater than zero.');
        }

        if (bccomp(sprintf('%.2f', $this->totalDebit()), sprintf('%.2f', $this->totalCredit()), 2) !== 0) {
            throw UnbalancedJournalException::forAmounts($this->totalDebit(), $this->totalCredit());
        }
    }
}
