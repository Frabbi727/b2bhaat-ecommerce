<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlashSale>
 */
class FlashSaleFactory extends Factory
{
    protected $model = FlashSale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true).' Sale';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'status' => FlashSaleStatus::Draft,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'is_featured' => false,
            'display_priority' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleStatus::Draft,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleStatus::Scheduled,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleStatus::Paused,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'paused_at' => now()->subMinutes(10),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleStatus::Ended,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'ended_at' => now()->subDay(),
        ]);
    }
}
