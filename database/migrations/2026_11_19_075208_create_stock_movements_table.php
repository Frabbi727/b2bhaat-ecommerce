<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AR-08: a ledger of every stock change (how much, why, by whom, for which order), so a variation's stock can be
 * explained and rebuilt. Each existing variation starts with one "opening balance" row of its current stock, so
 * stock = sum of movements from day one. Rows are kept when a variation is deleted (the SKU is copied in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->string('sku')->nullable();
            $table->integer('quantity_change');
            $table->integer('quantity_after');
            $table->string('reason', 30);
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_variation_id', 'id']);
            $table->index('reason');
        });

        DB::table('stock_movements')->insertUsing(
            ['product_variation_id', 'sku', 'quantity_change', 'quantity_after', 'reason', 'note'],
            DB::table('product_variations')
                ->where('stock_quantity', '!=', 0)
                ->select(['id', 'sku', 'stock_quantity', 'stock_quantity', DB::raw("'opening_balance'"), DB::raw("'Stock when the ledger started'")]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
