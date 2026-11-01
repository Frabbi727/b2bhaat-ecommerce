<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->after('slug');
            $table->string('model_number', 100)->nullable()->after('sku');
            $table->decimal('regular_price', 12, 2)->nullable()->after('buy_price');
            $table->string('warranty_period', 100)->nullable()->after('regular_price');
            $table->text('warranty_policy')->nullable()->after('warranty_period');
            $table->string('delivery_info')->nullable()->after('warranty_policy');
            $table->json('specifications')->nullable()->after('full_description');
            $table->json('features')->nullable()->after('specifications');
            $table->boolean('is_featured')->default(false)->after('is_active');
            $table->boolean('is_flash_sale')->default(false)->after('is_featured');
            $table->decimal('flash_sale_price', 12, 2)->nullable()->after('is_flash_sale');
            $table->timestamp('flash_sale_end')->nullable()->after('flash_sale_price');
            $table->unsignedInteger('views_count')->default(0)->after('flash_sale_end');
            $table->unsignedInteger('sales_count')->default(0)->after('views_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sku',
                'model_number',
                'regular_price',
                'warranty_period',
                'warranty_policy',
                'delivery_info',
                'specifications',
                'features',
                'is_featured',
                'is_flash_sale',
                'flash_sale_price',
                'flash_sale_end',
                'views_count',
                'sales_count',
            ]);
        });
    }
};
