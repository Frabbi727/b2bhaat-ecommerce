<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA-027: money owed back to customers on cancellation of a paid order or an approved refund return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('sub_order_id')->nullable()->unique()->constrained('sub_orders')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->string('status', 20)->default('pending');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
