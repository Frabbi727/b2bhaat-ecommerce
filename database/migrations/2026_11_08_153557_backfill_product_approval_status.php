<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INV-8: grandfathers the existing catalog as approved. Kept apart from the column migration and run outside a
 * transaction, so each 1000-row chunk commits on its own and the products table is never locked for the whole backfill.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        self::backfill();
    }

    /**
     * Every product becomes approved, dated at creation, with no reviewer; `updated_at` is left untouched.
     */
    public static function backfill(): void
    {
        DB::table('products')->orderBy('id')->select('id')->chunkById(1000, function ($products): void {
            DB::table('products')
                ->whereIn('id', $products->pluck('id'))
                ->update([
                    'approval_status' => 'approved',
                    'approved_at' => DB::raw('created_at'),
                ]);
        });
    }

    /**
     * Nothing to undo: the column migration's down() drops the approval columns.
     */
    public function down(): void {}
};
