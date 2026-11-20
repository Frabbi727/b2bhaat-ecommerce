<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INV-4: an order line's option (variation) can no longer be deleted. The link used to be cleared on delete, so the
 * order lost which size or colour was bought; the database now refuses the delete instead.
 *
 * No row is changed. Order lines that already lost their option stay as they are; their number is printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('product_variations')->restrictOnDelete();
        });

        $orphaned = DB::table('order_items')
            ->whereNull('variation_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('product_variations')
                ->whereColumn('product_variations.product_id', 'order_items.product_id'))
            ->count();

        if ($orphaned > 0) {
            fwrite(STDERR, "INV-4: {$orphaned} order line(s) already lost their option before this change. They are kept as they are; the invoice shows the product without its option.\n");
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['variation_id']);
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
        });
    }
};
