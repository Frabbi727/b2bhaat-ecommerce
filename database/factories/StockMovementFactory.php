<?php

namespace Database\Factories;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $change = fake()->numberBetween(-10, 50);

        return [
            'product_variation_id' => null,
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'quantity_change' => $change,
            'quantity_after' => max(0, $change),
            'reason' => StockMovement::REASON_ADJUSTMENT,
        ];
    }
}
