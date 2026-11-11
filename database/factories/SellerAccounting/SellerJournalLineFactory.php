<?php

declare(strict_types=1);

namespace Database\Factories\SellerAccounting;

use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\SellerAccounting\SellerJournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A single line; the caller is responsible for keeping the entry balanced.
 *
 * @extends Factory<SellerJournalLine>
 */
class SellerJournalLineFactory extends Factory
{
    protected $model = SellerJournalLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_journal_entry_id' => SellerJournalEntry::factory(),
            'seller_id' => fn (array $attributes): int => SellerJournalEntry::query()->whereKey($attributes['seller_journal_entry_id'])->value('seller_id'),
            'seller_account_id' => fn (array $attributes): int => SellerAccount::factory()->create(['seller_id' => $attributes['seller_id']])->id,
            'debit' => 0,
            'credit' => 0,
        ];
    }
}
