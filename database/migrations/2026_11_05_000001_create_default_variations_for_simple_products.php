<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRA-011: additive data migration. Every product without any variation gets a default variation
 * with stock 0 (never oversell); sellers enter real stock before go-live. down() removes only those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $productIds = [];

        DB::table('products')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('product_variations')
                ->whereColumn('product_variations.product_id', 'products.id'))
            ->orderBy('id')
            ->select(['id', 'sku', 'slug', 'buy_price'])
            ->each(function (object $product) use (&$productIds): void {
                DB::table('product_variations')->insert([
                    'product_id' => $product->id,
                    'sku' => Product::defaultVariationSku((int) $product->id, $product->sku, $product->slug),
                    'buy_price' => $product->buy_price ?? 0,
                    'stock_quantity' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $productIds[] = $product->id;
            });

        if ($productIds !== []) {
            Log::notice('PRA-011: created default variations (stock 0) for products', ['product_ids' => $productIds]);
        }
    }

    public function down(): void
    {
        DB::table('product_variations')
            ->where('sku', 'like', '%'.Product::DEFAULT_VARIATION_SUFFIX)
            ->where('stock_quantity', 0)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('order_items')
                ->whereColumn('order_items.variation_id', 'product_variations.id'))
            ->delete();
    }
};
