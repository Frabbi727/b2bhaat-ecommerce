<?php

declare(strict_types=1);

namespace Database\Factories\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A seller's own (non-system) account. Defaults to an expense account.
 *
 * @extends Factory<SellerAccount>
 */
class SellerAccountFactory extends Factory
{
    protected $model = SellerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'seller_id' => User::factory(),
            'code' => (string) $this->faker->unique()->numberBetween(5500, 5999),
            'name' => $name,
            'slug' => 'custom_'.Str::slug($name, '_').'_'.Str::lower(Str::random(4)),
            'type' => AccountType::EXPENSE,
            'is_system' => false,
            'is_active' => true,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
