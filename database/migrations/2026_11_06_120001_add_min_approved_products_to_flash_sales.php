<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flash_sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('flash_sales', 'min_approved_products')) {
                $table->unsignedInteger('min_approved_products')->default(1)->after('requires_approval');
            }
        });

        Schema::table('flash_sale_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('flash_sale_products', 'seller_notes')) {
                $table->text('seller_notes')->nullable()->after('rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flash_sale_products', function (Blueprint $table): void {
            if (Schema::hasColumn('flash_sale_products', 'seller_notes')) {
                $table->dropColumn('seller_notes');
            }
        });

        Schema::table('flash_sales', function (Blueprint $table): void {
            if (Schema::hasColumn('flash_sales', 'min_approved_products')) {
                $table->dropColumn('min_approved_products');
            }
        });
    }
};
