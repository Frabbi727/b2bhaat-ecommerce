<?php

use App\Services\Inventory\ProductCodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Every product gets a scannable barcode, and products listed before SKUs were automatic get one too.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 13)->nullable()->unique()->after('sku');
        });

        $categorySlugs = DB::table('categories')->pluck('slug', 'id');

        DB::table('products')->orderBy('id')->select(['id', 'sku', 'category_id'])->chunkById(200, function ($products) use ($categorySlugs): void {
            foreach ($products as $product) {
                $updates = ['barcode' => ProductCodeGenerator::barcode((int) $product->id)];

                if (blank($product->sku)) {
                    $letters = Str::upper(Str::substr((string) preg_replace('/[^A-Za-z]/', '', (string) ($categorySlugs[$product->category_id] ?? '')), 0, 3));
                    $updates['sku'] = (strlen($letters) === 3 ? $letters : 'PRD').'-'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT);
                }

                DB::table('products')->where('id', $product->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
