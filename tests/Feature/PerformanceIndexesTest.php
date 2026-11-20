<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PERF-1: hot foreign keys and status filters are indexed, so lookups stay fast as tables grow.
 */
class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function expectedIndexes(): array
    {
        // The app is not booted while data providers run, so database_path() is unavailable here.
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_09_044216_add_performance_indexes.php';

        $cases = [];
        foreach ($migration::indexes() as $table => $columnSets) {
            foreach ($columnSets as $columns) {
                $cases[$table.'('.implode(',', $columns).')'] = [$table, $columns];
            }
        }

        return $cases;
    }

    /**
     * @param  list<string>  $columns
     */
    #[DataProvider('expectedIndexes')]
    public function test_column_set_is_indexed(string $table, array $columns): void
    {
        $leadingColumns = collect(Schema::getIndexes($table))
            ->map(fn (array $index): array => array_slice($index['columns'], 0, count($columns)));

        $this->assertTrue(
            $leadingColumns->contains($columns),
            "Expected an index on {$table}(".implode(',', $columns).').'
        );
    }

    public function test_the_hot_lookups_are_covered(): void
    {
        $migration = require database_path('migrations/2026_11_09_044216_add_performance_indexes.php');
        $indexes = $migration::indexes();

        $this->assertContains(['sub_order_id'], $indexes['order_items']);
        $this->assertContains(['parent_order_id'], $indexes['sub_orders']);
        $this->assertContains(['product_id'], $indexes['product_prices']);
        $this->assertContains(['seller_wallet_id'], $indexes['wallet_transactions']);
        $this->assertContains(['is_active', 'approval_status'], $indexes['products']);
    }

    public function test_rolling_back_removes_only_the_new_indexes(): void
    {
        $migration = require database_path('migrations/2026_11_09_044216_add_performance_indexes.php');

        $migration->down();

        $this->assertFalse(collect(Schema::getIndexes('order_items'))->contains(fn (array $index): bool => $index['columns'] === ['sub_order_id']));
        $this->assertTrue(Schema::hasTable('order_items'));

        $migration->up();

        $this->assertTrue(collect(Schema::getIndexes('order_items'))->contains(fn (array $index): bool => $index['columns'] === ['sub_order_id']));
    }
}
