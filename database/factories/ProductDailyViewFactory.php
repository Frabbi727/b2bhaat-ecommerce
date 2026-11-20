<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductDailyView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Callers attach a product with ->for($product) (there is no product factory).
 *
 * @extends Factory<ProductDailyView>
 */
class ProductDailyViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now(config('app.display_timezone'))->toDateString(),
            'views' => fake()->numberBetween(1, 50),
        ];
    }
}
