<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone', 32);
            $table->string('company_name')->nullable();
            $table->integer('target_quantity');
            $table->decimal('target_unit_price', 12, 2)->nullable();
            $table->string('destination_district', 100)->default('Dhaka');
            $table->text('specifications')->nullable();
            $table->string('status', 32)->default('pending'); // pending, quoted, accepted, rejected
            $table->decimal('seller_quoted_price', 12, 2)->nullable();
            $table->text('seller_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_requests');
    }
};
