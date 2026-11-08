<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\PriceSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Needs the database: channel slugs are looked up for validation and products are built through factories.
 */
class PriceSetTest extends TestCase
{
    use RefreshDatabase;

    private SalesChannel $retail;

    private SalesChannel $wholesale;

    private SalesChannel $both;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
        $this->wholesale = SalesChannel::firstOrCreate(['slug' => SalesChannel::WHOLESALE], ['name' => 'Wholesale', 'is_active' => true]);
        $this->both = SalesChannel::firstOrCreate(['slug' => SalesChannel::BOTH], ['name' => 'Both', 'is_active' => true]);
    }

    /**
     * @return array{sales_channel_id: int, variation_id: ?int, min_quantity: int, max_quantity: ?int, unit_price: string|int}
     */
    private function row(SalesChannel $channel, int $min, ?int $max, string|int $price = '100', ?int $variation = null): array
    {
        return [
            'sales_channel_id' => $channel->id,
            'variation_id' => $variation,
            'min_quantity' => $min,
            'max_quantity' => $max,
            'unit_price' => $price,
        ];
    }

    public function test_formatting_and_order_differences_are_equal(): void
    {
        $a = PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'min_quantity' => 1, 'max_quantity' => '', 'unit_price' => '100'],
            ['sales_channel_id' => $this->wholesale->id, 'variation_id' => null, 'min_quantity' => 50, 'max_quantity' => 99, 'unit_price' => 110],
        ], 120.0);
        $b = PriceSet::fromInput([
            ['sales_channel_id' => $this->wholesale->id, 'min_quantity' => '50', 'max_quantity' => 99, 'unit_price' => '110.00', 'junk' => 'x'],
            ['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'max_quantity' => null, 'unit_price' => '100.00'],
        ], 120.0);

        $this->assertTrue($a->equals($b));
        $this->assertSame($a->fingerprint(1, 2), $b->fingerprint(1, 2));
        $this->assertSame('100.00', $a->rows()[0]['unit_price']);
        $this->assertNull($a->rows()[0]['max_quantity']);
        $this->assertSame('120.00', $a->regularPrice());
    }

    public function test_changed_unit_price_changes_fingerprint(): void
    {
        $a = PriceSet::fromInput([$this->row($this->retail, 1, null, '100')], null);
        $b = PriceSet::fromInput([$this->row($this->retail, 1, null, '100.01')], null);

        $this->assertFalse($a->equals($b));
        $this->assertNotSame($a->fingerprint(1, 2), $b->fingerprint(1, 2));
    }

    public function test_changed_regular_price_changes_fingerprint(): void
    {
        $rows = [$this->row($this->retail, 1, null)];

        $this->assertNotSame(
            PriceSet::fromInput($rows, 150)->fingerprint(1, 2),
            PriceSet::fromInput($rows, null)->fingerprint(1, 2),
        );
    }

    public function test_changed_category_or_channel_changes_fingerprint(): void
    {
        $set = PriceSet::fromInput([$this->row($this->retail, 1, null)], null);

        $this->assertNotSame($set->fingerprint(1, 2), $set->fingerprint(1, 3));
        $this->assertNotSame($set->fingerprint(1, 2), $set->fingerprint(2, 2));
        $this->assertSame(64, strlen($set->fingerprint(1, 2)));
    }

    public function test_overlapping_tiers_are_reported(): void
    {
        $set = PriceSet::fromInput([
            $this->row($this->wholesale, 50, 99),
            $this->row($this->wholesale, 90, 150),
        ], null);

        $errors = $set->validate($this->wholesale);

        $this->assertSame(['Price tiers 50–99 and 90–150 overlap. Each quantity must fall in one tier only.'], $errors);
    }

    public function test_open_ended_tier_overlap_uses_plus(): void
    {
        $errors = PriceSet::fromInput([
            $this->row($this->wholesale, 50, null),
            $this->row($this->wholesale, 150, 200),
        ], null)->validate($this->wholesale);

        $this->assertSame(['Price tiers 50+ and 150–200 overlap. Each quantity must fall in one tier only.'], $errors);
    }

    public function test_adjacent_tiers_and_other_variations_do_not_overlap(): void
    {
        $set = PriceSet::fromInput([
            $this->row($this->wholesale, 50, 99),
            $this->row($this->wholesale, 100, null),
            $this->row($this->wholesale, 50, 99, '100', 7),
        ], null);

        $this->assertSame([], $set->validate($this->wholesale));
    }

    public function test_more_than_200_rows_is_refused(): void
    {
        $rows = [];
        for ($i = 1; $i <= 201; $i++) {
            $rows[] = $this->row($this->wholesale, $i, $i);
        }

        $this->assertContains('A product can have at most 200 price rows.', PriceSet::fromInput($rows, null)->validate($this->wholesale));
    }

    public function test_retail_product_without_retail_row_is_refused(): void
    {
        $set = PriceSet::fromInput([$this->row($this->wholesale, 50, null)], null);

        $this->assertSame(['Add a Retail price: shoppers need a price to buy this product.'], $set->validate($this->retail));
    }

    public function test_wholesale_product_without_wholesale_row_is_refused(): void
    {
        $set = PriceSet::fromInput([$this->row($this->retail, 1, null)], null);

        $this->assertSame(['Add at least one Wholesale price tier for business buyers.'], $set->validate($this->wholesale));
    }

    public function test_both_product_needs_both_rows(): void
    {
        $errors = PriceSet::fromInput([], null)->validate($this->both);

        $this->assertContains('Add a Retail price: shoppers need a price to buy this product.', $errors);
        $this->assertContains('Add at least one Wholesale price tier for business buyers.', $errors);
    }

    public function test_row_level_rules(): void
    {
        $errors = PriceSet::fromInput([
            $this->row($this->retail, 0, null, '0'),
            $this->row($this->wholesale, 10, 5),
        ], null)->validate($this->both);

        $this->assertContains('Every price must be at least ৳0.01.', $errors);
        $this->assertContains('Minimum quantity must be 1 or more.', $errors);
        $this->assertContains("Maximum quantity can't be below the minimum.", $errors);
    }

    public function test_variation_rows_are_kept(): void
    {
        $seller = User::factory()->create();
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Fabrics', 'slug' => 'fabrics', 'is_active' => true])->id,
            'sales_channel_id' => $this->both->id,
            'unit_id' => Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs'])->id,
            'title' => 'Cotton',
            'slug' => 'cotton',
            'regular_price' => 90,
            'has_variations' => true,
            'is_active' => true,
        ]);
        $variation = ProductVariation::create(['product_id' => $product->id, 'sku' => 'COT-1', 'stock_quantity' => 5, 'is_active' => true]);
        ProductPrice::create([
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'sales_channel_id' => $this->wholesale->id,
            'min_quantity' => 10,
            'max_quantity' => null,
            'unit_price' => 75,
        ]);
        ProductPrice::create([
            'product_id' => $product->id,
            'variation_id' => null,
            'sales_channel_id' => $this->retail->id,
            'min_quantity' => 1,
            'max_quantity' => null,
            'unit_price' => 100,
        ]);

        $set = PriceSet::fromProduct($product->fresh());

        $this->assertCount(2, $set->rows());
        $this->assertSame($variation->id, $set->rows()[1]['variation_id']);
        $this->assertSame('90.00', $set->regularPrice());
    }
}
