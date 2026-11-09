<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-1: PostgreSQL does not index foreign keys by itself, and the status columns every queue and report filters on
 * had no index either. This adds them. On PostgreSQL each index is built CONCURRENTLY (outside a transaction), so the
 * shop keeps taking orders while it runs. Only indexes; no data is changed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Column sets to index, per table. A set that is already covered by an index with the same leading columns is
     * skipped, so the migration is safe to run on any environment.
     *
     * @return array<string, list<list<string>>>
     */
    public static function indexes(): array
    {
        return [
            'accounting_journal_entries' => [['created_by_user_id'], ['reversal_journal_id'], ['status']],
            'accounting_journal_lines' => [['customer_id']],
            'categories' => [['parent_id']],
            'coupon_usages' => [['coupon_id'], ['order_id'], ['user_id']],
            'customer_addresses' => [['user_id']],
            'flash_sale_products' => [['reviewed_by'], ['status']],
            'flash_sales' => [['created_by']],
            'order_items' => [['flash_sale_product_id'], ['product_id'], ['sales_channel_id'], ['sub_order_id'], ['variation_id']],
            'order_tracking_logs' => [['fulfillment_status_id'], ['sub_order_id']],
            'orders' => [['coupon_id'], ['customer_id'], ['order_status_id'], ['payment_method_id'], ['payment_status_id'], ['created_at']],
            'product_change_requests' => [
                ['base_category_id'], ['base_sales_channel_id'], ['proposed_category_id'], ['proposed_sales_channel_id'],
                ['reviewed_by_user_id'], ['submitted_by_user_id'], ['superseded_by_id'],
            ],
            'product_images' => [['product_id']],
            'product_prices' => [['product_id'], ['sales_channel_id'], ['variation_id']],
            'product_reviews' => [['order_id'], ['sub_order_id'], ['user_id']],
            'product_variations' => [['product_id']],
            'products' => [
                ['approved_by_user_id'], ['brand_id'], ['category_id'], ['reviewed_by_user_id'], ['sales_channel_id'],
                ['seller_id'], ['unit_id'], ['is_active', 'approval_status'],
            ],
            'refunds' => [['order_id']],
            'return_requests' => [['status']],
            'rfq_requests' => [['buyer_id'], ['product_id'], ['seller_id'], ['status']],
            'seller_invoices' => [['subscription_id'], ['status', 'invoice_type']],
            'seller_payments' => [['seller_id'], ['verified_by_user_id'], ['status']],
            'seller_profiles' => [['monetization_status']],
            'seller_subscriptions' => [['plan_id'], ['status']],
            'seller_suspension_events' => [['action_by_user_id']],
            'sub_orders' => [['fulfillment_status_id'], ['parent_order_id'], ['seller_id'], ['created_at']],
            'users' => [['role_id']],
            'variation_attribute_values' => [['attribute_value_id']],
            'wallet_transactions' => [['seller_wallet_id'], ['status']],
            'wishlists' => [['product_id']],
        ];
    }

    public function up(): void
    {
        foreach (self::applicable() as [$table, $columns, $name]) {
            // A concurrent build that failed leaves an invalid index under our name; drop it so it is built again
            // (Wave 1 review M-7).
            if (in_array($name, self::invalidIndexes($table), true)) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
            }

            if (self::isCovered($table, $columns)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                $quoted = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $columns));
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS \"{$name}\" ON \"{$table}\" ({$quoted})");

                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::applicable() as [$table, $columns, $name]) {
            if (! Schema::hasIndex($table, $name)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");

                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }

    /**
     * Each set whose table and columns exist here, with the name this migration gives it.
     *
     * @return list<array{0: string, 1: list<string>, 2: string}>
     */
    private static function applicable(): array
    {
        $sets = [];
        foreach (self::indexes() as $table => $columnSets) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columnSets as $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    continue;
                }

                $sets[] = [$table, $columns, 'perf_'.$table.'_'.implode('_', $columns).'_index'];
            }
        }

        return $sets;
    }

    /**
     * True when an existing index already starts with these columns (a composite or unique index counts).
     *
     * @param  list<string>  $columns
     */
    private static function isCovered(string $table, array $columns): bool
    {
        $invalid = self::invalidIndexes($table);

        foreach (Schema::getIndexes($table) as $index) {
            // An invalid index (a failed concurrent build) is never used by PostgreSQL, so it covers nothing.
            if (in_array($index['name'], $invalid, true)) {
                continue;
            }

            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the table's indexes PostgreSQL marked invalid. Other databases have none.
     *
     * @return list<string>
     */
    private static function invalidIndexes(string $table): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        return array_map(fn (object $row): string => (string) $row->name, DB::select(
            'select c.relname as name from pg_index i
                join pg_class c on c.oid = i.indexrelid
                join pg_class t on t.oid = i.indrelid
                join pg_namespace n on n.oid = t.relnamespace
            where t.relname = ? and n.nspname = current_schema() and not i.indisvalid',
            [$table],
        ));
    }
};
