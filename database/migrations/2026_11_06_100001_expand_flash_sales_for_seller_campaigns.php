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
            $table->timestamp('registration_starts_at')->nullable()->after('ends_at');
            $table->timestamp('registration_ends_at')->nullable()->after('registration_starts_at');
            $table->decimal('min_discount_percentage', 5, 2)->default(5.00)->after('registration_ends_at');
            $table->decimal('max_discount_percentage', 5, 2)->default(80.00)->after('min_discount_percentage');
            $table->unsignedInteger('max_products_per_seller')->default(10)->after('max_discount_percentage');
            $table->unsignedInteger('min_stock_per_product')->default(1)->after('max_products_per_seller');
            $table->unsignedInteger('max_stock_per_product')->nullable()->after('min_stock_per_product');
            $table->boolean('requires_approval')->default(true)->after('max_stock_per_product');
            $table->string('seller_eligibility_type', 30)->default('all')->after('min_approved_products');
            $table->decimal('minimum_seller_rating', 3, 2)->nullable()->after('seller_eligibility_type');
        });

        Schema::table('flash_sale_products', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->after('product_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->after('discount_value');
            $table->decimal('original_retail_price', 12, 2)->default(0.00)->after('status');
            $table->decimal('campaign_price', 12, 2)->default(0.00)->after('original_retail_price');
            $table->unsignedInteger('units_sold')->default(0)->after('stock_limit');
            $table->text('rejection_reason')->nullable()->after('sort_order');
            $table->foreignId('reviewed_by')->nullable()->after('seller_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['seller_id', 'status']);
            $table->index(['flash_sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('flash_sale_products', function (Blueprint $table): void {
            $table->dropIndex(['seller_id', 'status']);
            $table->dropIndex(['flash_sale_id', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn([
                'status',
                'original_retail_price',
                'campaign_price',
                'units_sold',
                'rejection_reason',
                'reviewed_at',
            ]);
        });

        Schema::table('flash_sales', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_starts_at',
                'registration_ends_at',
                'min_discount_percentage',
                'max_discount_percentage',
                'max_products_per_seller',
                'min_stock_per_product',
                'max_stock_per_product',
                'requires_approval',
                'seller_eligibility_type',
                'minimum_seller_rating',
            ]);
        });
    }
};
