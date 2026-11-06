<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('banner_path')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_featured')->default(false);
            $table->integer('display_priority')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('flash_sale_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->unsignedInteger('stock_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['flash_sale_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('flash_sale_product_id')->nullable()->after('sales_channel_id')
                ->constrained('flash_sale_products')->restrictOnDelete();
            $table->decimal('original_unit_price', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flash_sale_product_id');
            $table->dropColumn('original_unit_price');
        });

        Schema::dropIfExists('flash_sale_products');
        Schema::dropIfExists('flash_sales');
    }
};
