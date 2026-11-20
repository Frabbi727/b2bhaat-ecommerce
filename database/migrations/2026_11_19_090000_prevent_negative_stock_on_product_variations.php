<?php

use App\Models\StockMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AR-09 (owner decision 2026-11-19): no backorders, so stock can never be below 0. Any variation already below 0
 * (sold while the old "allow orders at 0 stock" setting was on) is set to 0 and the reset is written to the stock
 * ledger. On PostgreSQL a CHECK constraint then refuses negative stock from any code path; SQLite can't add one to
 * an existing table, and the application check covers it there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_variations')
            ->where('stock_quantity', '<', 0)
            ->orderBy('id')
            ->get(['id', 'sku', 'stock_quantity'])
            ->each(function (object $variation): void {
                DB::table('product_variations')->where('id', $variation->id)->update(['stock_quantity' => 0, 'updated_at' => now()]);
                DB::table('stock_movements')->insert([
                    'product_variation_id' => $variation->id,
                    'sku' => $variation->sku,
                    'quantity_change' => -$variation->stock_quantity,
                    'quantity_after' => 0,
                    'reason' => StockMovement::REASON_ADJUSTMENT,
                    'note' => "Stock was {$variation->stock_quantity}; reset to 0 when backorders were turned off for good",
                    'created_at' => now(),
                ]);
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_variations ADD CONSTRAINT product_variations_stock_not_negative CHECK (stock_quantity >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_variations DROP CONSTRAINT IF EXISTS product_variations_stock_not_negative');
        }
    }
};
