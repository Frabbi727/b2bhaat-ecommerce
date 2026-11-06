<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FlashSaleDiscountType;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * There is no Product factory, so product_id defaults to the first seeded product;
 * pass product_id explicitly when a test needs a specific one.
 *
 * @extends Factory<FlashSaleProduct>
 */
class FlashSaleProductFactory extends Factory
{
    protected $model = FlashSaleProduct::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flash_sale_id' => FlashSale::factory(),
            'product_id' => fn (): mixed => Product::query()->value('id'),
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 10,
            'stock_limit' => null,
            'per_customer_limit' => null,
            'sort_order' => 0,
        ];
    }
}
