<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\SellerAccounting\SellerJournalEntry;
use Carbon\CarbonInterface;

/**
 * A seller-books entry being put together before it is posted. Lines name their account by slug (system
 * accounts) or by id (any account the seller owns).
 */
final class SellerEntryDraft
{
    /**
     * @var list<array{account: string|int, debit: float, credit: float, product_id: ?int, sub_order_id: ?int, narration: ?string}>
     */
    public array $lines = [];

    public function __construct(
        public readonly int $sellerId,
        public readonly SellerEntryType $type,
        public readonly string $description,
        public readonly CarbonInterface|string $entryDate,
        public readonly string $idempotencyKey,
        public readonly string $source = SellerJournalEntry::SOURCE_AUTO,
        public readonly ?int $walletTransactionId = null,
        public readonly ?int $subOrderId = null,
        public readonly ?int $createdBy = null,
        public readonly ?int $reversalOfId = null,
    ) {}

    /**
     * Debit an account; a negative amount is booked as a credit instead.
     */
    public function debit(string|int $account, float $amount, ?int $productId = null, ?string $narration = null, ?int $subOrderId = null): self
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            return $this;
        }

        $this->lines[] = [
            'account' => $account,
            'debit' => $amount > 0 ? $amount : 0.0,
            'credit' => $amount < 0 ? -$amount : 0.0,
            'product_id' => $productId,
            'sub_order_id' => $subOrderId ?? $this->subOrderId,
            'narration' => $narration,
        ];

        return $this;
    }

    /**
     * Credit an account; a negative amount is booked as a debit instead.
     */
    public function credit(string|int $account, float $amount, ?int $productId = null, ?string $narration = null, ?int $subOrderId = null): self
    {
        return $this->debit($account, -$amount, $productId, $narration, $subOrderId);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Debits minus credits, in poisha so rounding cannot hide an imbalance. */
    public function imbalancePoisha(): int
    {
        $poisha = 0;
        foreach ($this->lines as $line) {
            $poisha += (int) round($line['debit'] * 100) - (int) round($line['credit'] * 100);
        }

        return $poisha;
    }
}
