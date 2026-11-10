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

    /**
     * ACC-15: totals are the sum of each line rounded to whole poisha, i.e. exactly what is stored, so a journal
     * that only balances before rounding is refused. Integer maths avoids float drift (0.1 + 0.2).
     */
    public function totalDebit(): float
    {
        return $this->totalPoisha('debit') / 100;
    }

    public function totalCredit(): float
    {
        return $this->totalPoisha('credit') / 100;
    }

    public function isBalanced(): bool
    {
        return count($this->lines) >= 2
            && $this->totalPoisha('debit') > 0
            && $this->totalPoisha('debit') === $this->totalPoisha('credit');
    }

    public function validate(): void
    {
        if (count($this->lines) < 2) {
            throw new UnbalancedJournalException('Journal entry must have at least 2 lines.');
        }

        if ($this->totalPoisha('debit') <= 0) {
            throw new UnbalancedJournalException('Journal entry total debit must be greater than zero.');
        }

        if ($this->totalPoisha('debit') !== $this->totalPoisha('credit')) {
            throw UnbalancedJournalException::forAmounts($this->totalDebit(), $this->totalCredit());
        }
    }

    /**
     * Amount in whole poisha, rounded the same way the stored line is (half away from zero, 2 decimals).
     */
    public static function toPoisha(float $amount): int
    {
        return (int) round(round($amount, 2) * 100);
    }

    private function totalPoisha(string $side): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            $sum += self::toPoisha($line->{$side});
        }

        return $sum;
    }
}
