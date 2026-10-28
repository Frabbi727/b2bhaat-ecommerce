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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_status_id')->constrained('order_statuses')->restrictOnDelete();
            $table->foreignId('payment_status_id')->constrained('payment_statuses')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->jsonb('shipping_address');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('shipping_amount', 12, 2)->default(0.00);
            $table->timestamps();
        });

        Schema::create('sub_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('sub_order_number', 64)->unique();
            $table->foreignId('fulfillment_status_id')->constrained('fulfillment_statuses')->restrictOnDelete();
            $table->string('courier_name', 100)->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained('sub_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->foreignId('sales_channel_id')->constrained('sales_channels')->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->integer('quantity');
            $table->decimal('total_price', 12, 2);
            $table->timestamps();
        });

        Schema::create('order_tracking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained('sub_orders')->cascadeOnDelete();
            $table->foreignId('fulfillment_status_id')->constrained('fulfillment_statuses')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('hub_location')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tracking_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('sub_orders');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('payment_methods');
    }
};
