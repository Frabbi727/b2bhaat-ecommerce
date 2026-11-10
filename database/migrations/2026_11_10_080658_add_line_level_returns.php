<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-4: a buyer can return some items of a parcel. Each return lists its items and the money it moved; a parcel
 * can then have several refunds and several return rows in the seller's wallet, one set per return request.
 * Adds tables, columns and indexes only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->decimal('seller_amount', 12, 2)->nullable();
            $table->decimal('discount_share', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id']);
            $table->index('order_item_id');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('return_request_id')->nullable()->after('sub_order_id')->unique()->constrained('return_requests')->restrictOnDelete();
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique('refunds_sub_order_id_unique');
            $table->index('sub_order_id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->foreignId('return_request_id')->nullable()->after('sub_order_id')->constrained('return_requests')->restrictOnDelete();
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique('wallet_transactions_sub_order_type_unique');
            $table->unique(['return_request_id', 'transaction_type'], 'wallet_transactions_return_type_unique');
        });

        // Still one row per sub-order and type for everything that is not a single return's own row
        DB::statement('CREATE UNIQUE INDEX wallet_transactions_sub_order_type_unique ON wallet_transactions (sub_order_id, transaction_type) WHERE return_request_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wallet_transactions_sub_order_type_unique');

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique('wallet_transactions_return_type_unique');
            $table->dropConstrainedForeignId('return_request_id');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->unique(['sub_order_id', 'transaction_type'], 'wallet_transactions_sub_order_type_unique');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex(['sub_order_id']);
            $table->unique('sub_order_id');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique(['return_request_id']);
            $table->dropConstrainedForeignId('return_request_id');
        });

        Schema::dropIfExists('return_request_items');
    }
};
