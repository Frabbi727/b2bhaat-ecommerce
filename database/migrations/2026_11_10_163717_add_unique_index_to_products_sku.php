<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INV-11: one SKU names one product. Products listed before SKUs were generated kept what the seller typed, so
     * two of them may share one. The migration then stops and names them; it never renames a seller's SKU itself.
     */
    public function up(): void
    {
        $duplicates = DB::table('products')
            ->whereNotNull('sku')
            ->select('sku')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('sku')
            ->limit(20)
            ->pluck('sku');

        if ($duplicates->isNotEmpty()) {
            $clashes = $duplicates->map(function (string $sku): string {
                $ids = DB::table('products')->where('sku', $sku)->orderBy('id')->pluck('id')->map(fn ($id): string => '#'.$id);

                return "{$sku}: products {$ids->implode(', ')}";
            });

            throw new RuntimeException(
                'Some products share a SKU, so the SKU cannot be made unique yet. Give each of these products its own SKU, then run the migration again. '
                .$clashes->implode('; ')
            );
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->unique('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['sku']);
        });
    }
};
