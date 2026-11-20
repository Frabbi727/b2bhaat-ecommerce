<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * INV-11: a product SKU names one product. Generated SKUs are unique by construction; the database now refuses a
 * duplicate from any other path, and the migration stops (naming the clash) instead of renaming a seller's SKU.
 */
class ProductSkuUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_two_products_cannot_share_a_sku(): void
    {
        [$first, $second] = $this->twoProducts();

        $this->expectException(QueryException::class);
        $second->forceFill(['sku' => $first->sku])->saveQuietly();
    }

    public function test_products_without_a_sku_do_not_clash(): void
    {
        [$first, $second] = $this->twoProducts();

        DB::table('products')->whereIn('id', [$first->id, $second->id])->update(['sku' => null]);

        $this->assertSame(2, Product::whereIn('id', [$first->id, $second->id])->whereNull('sku')->count());
    }

    public function test_a_copied_product_gets_its_own_sku(): void
    {
        $original = Product::orderBy('id')->firstOrFail();

        $copy = $this->copyOf($original);

        $this->assertNotSame($original->sku, $copy->sku);
        $this->assertNotNull($copy->sku);
    }

    public function test_the_migration_stops_and_names_existing_duplicates(): void
    {
        $migration = $this->migration();
        $migration->down();

        [$first, $second] = $this->twoProducts();
        DB::table('products')->where('id', $second->id)->update(['sku' => $first->sku]);

        try {
            $migration->up();
            $this->fail('The migration should refuse duplicate SKUs.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($first->sku, $exception->getMessage());
            $this->assertStringContainsString("#{$first->id}", $exception->getMessage());
            $this->assertStringContainsString("#{$second->id}", $exception->getMessage());
        }

        // Nothing was renamed
        $this->assertSame($first->sku, DB::table('products')->where('id', $second->id)->value('sku'));
        $this->assertFalse($this->hasSkuUniqueIndex());
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function twoProducts(): array
    {
        $first = Product::orderBy('id')->firstOrFail();

        return [$first, $this->copyOf($first)];
    }

    private function copyOf(Product $original): Product
    {
        $copy = $original->replicate(['slug', 'barcode']);
        $copy->slug = $original->slug.'-copy';
        $copy->save();

        return $copy->fresh();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_10_163717_add_unique_index_to_products_sku.php');
    }

    private function hasSkuUniqueIndex(): bool
    {
        return collect(Schema::getIndexes('products'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['sku']);
    }
}
