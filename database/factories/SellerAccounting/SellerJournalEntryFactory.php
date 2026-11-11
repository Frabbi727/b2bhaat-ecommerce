<?php

declare(strict_types=1);

namespace Database\Factories\SellerAccounting;

use App\Enums\SellerAccounting\SellerEntryType;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An entry header only; post real entries through SellerJournalPostingService so they balance.
 *
 * @extends Factory<SellerJournalEntry>
 */
class SellerJournalEntryFactory extends Factory
{
    protected $model = SellerJournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => User::factory(),
            'entry_number' => 'SJ-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'entry_date' => now()->toDateString(),
            'description' => $this->faker->sentence(4),
            'source' => SellerJournalEntry::SOURCE_MANUAL,
            'source_type' => SellerEntryType::Manual,
            'idempotency_key' => 'factory:'.Str::uuid()->toString(),
        ];
    }
}
