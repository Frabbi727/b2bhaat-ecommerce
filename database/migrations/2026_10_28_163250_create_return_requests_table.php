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
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_order_id')->constrained('sub_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 50);
            $table->string('requested_action', 50)->default('refund');
            $table->text('description');
            $table->text('image_proof_url')->nullable();
            $table->string('status', 50)->default('pending');
            $table->text('seller_note')->nullable();
            $table->text('admin_arbitration_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['sub_order_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};
