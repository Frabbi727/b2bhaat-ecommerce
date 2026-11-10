<?php

namespace Database\Factories;

use App\Models\ReturnRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnRequestItem>
 */
class ReturnRequestItemFactory extends Factory
{
    /**
     * The return and order item are set by the caller: neither has a factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 1,
        ];
    }
}
