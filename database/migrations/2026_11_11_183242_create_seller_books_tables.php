<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller books: each seller's own double-entry ledger, kept from the seller's point of view. Separate from the
 * platform ledger (accounting_*), which it never touches. seller_id is the seller's user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('slug', 64);
            $table->string('type', 16);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['seller_id', 'code']);
            $table->unique(['seller_id', 'slug']);
            $table->index(['seller_id', 'type']);
        });

        Schema::create('seller_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('entry_number', 20);
            $table->date('entry_date');
            $table->string('description');
            $table->string('source', 10);
            $table->string('source_type', 32);
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->foreignId('reversal_of_id')->nullable()->constrained('seller_journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seller_id', 'entry_number']);
            $table->index(['seller_id', 'entry_date']);
        });

        Schema::create('seller_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_journal_entry_id')->constrained('seller_journal_entries')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_account_id')->constrained('seller_accounts')->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
            $table->string('narration')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'seller_account_id']);
            $table->index(['seller_id', 'product_id']);
            $table->index(['seller_id', 'sub_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_journal_lines');
        Schema::dropIfExists('seller_journal_entries');
        Schema::dropIfExists('seller_accounts');
    }
};
