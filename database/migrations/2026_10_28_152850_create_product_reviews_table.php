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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('title', 150)->nullable();
            $table->text('comment');
            $table->boolean('is_verified_purchase')->default(false);
            $table->boolean('is_approved')->default(true);
            $table->text('seller_reply')->nullable();
            $table->timestamp('seller_replied_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'is_approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
