<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA-044: deleting a user must never cascade away their wallet and ledger history.
 * Changes only FK delete rules (no data change); down() restores the previous cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setDeleteRule('restrict');
    }

    public function down(): void
    {
        $this->setDeleteRule('cascade');
    }

    private function setDeleteRule(string $rule): void
    {
        Schema::table('seller_wallets', function (Blueprint $table) use ($rule) {
            $table->dropForeign(['seller_id']);
            $table->foreign('seller_id')->references('id')->on('users')->onDelete($rule);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) use ($rule) {
            $table->dropForeign(['seller_wallet_id']);
            $table->foreign('seller_wallet_id')->references('id')->on('seller_wallets')->onDelete($rule);
        });
    }
};
