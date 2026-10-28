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
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('available_balance', 12, 2)->default(0.00);
            $table->decimal('escrow_held_balance', 12, 2)->default(0.00);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_wallet_id')->constrained('seller_wallets')->cascadeOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
            $table->string('transaction_type', 50); // 'escrow_hold', 'escrow_release', 'payout'
            $table->decimal('amount', 12, 2);
            $table->decimal('running_balance', 12, 2);
            $table->text('reference_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('seller_wallets');
    }
};
