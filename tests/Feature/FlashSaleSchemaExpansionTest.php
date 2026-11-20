<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlashSaleSchemaExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_flash_sales_table_has_seller_campaign_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('flash_sales', [
                'registration_starts_at',
                'registration_ends_at',
                'min_discount_percentage',
                'max_discount_percentage',
                'max_products_per_seller',
                'min_stock_per_product',
                'max_stock_per_product',
                'requires_approval',
                'seller_eligibility_type',
                'minimum_seller_rating',
            ])
        );
    }

    public function test_flash_sale_products_table_has_submission_and_counter_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('flash_sale_products', [
                'seller_id',
                'status',
                'original_retail_price',
                'campaign_price',
                'units_sold',
                'rejection_reason',
                'reviewed_by',
                'reviewed_at',
            ])
        );
    }

    public function test_flash_sale_categories_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('flash_sale_categories'));
        $this->assertTrue(
            Schema::hasColumns('flash_sale_categories', [
                'flash_sale_id',
                'category_id',
            ])
        );
    }

    public function test_migrations_can_be_rolled_back(): void
    {
        $targetMigration = '2026_11_06_100001_expand_flash_sales_for_seller_campaigns';
        $steps = DB::table('migrations')->where('migration', '>=', $targetMigration)->count();

        $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('flash_sale_categories'));
        $this->assertFalse(Schema::hasColumn('flash_sale_products', 'seller_id'));
        $this->assertFalse(Schema::hasColumn('flash_sales', 'registration_starts_at'));

        // Verify clean re-application (bidirectional idempotency)
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('flash_sale_categories'));
        $this->assertTrue(Schema::hasColumn('flash_sale_products', 'seller_id'));
        $this->assertTrue(Schema::hasColumn('flash_sales', 'registration_starts_at'));
    }
}
