<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR-11 / DB-4/5: deleting a user used to delete their seller bills, payments, suspensions, books and return
 * requests with them. Now the database refuses to delete a user while that history exists, as it already does for
 * orders and wallets. Nothing else changes.
 */
return new class extends Migration
{
    /** @var array<string, string> table => column pointing at users */
    private const KEPT = [
        'seller_subscriptions' => 'seller_id',
        'seller_invoices' => 'seller_id',
        'seller_payments' => 'seller_id',
        'seller_suspension_events' => 'seller_id',
        'seller_journal_entries' => 'seller_id',
        'seller_journal_lines' => 'seller_id',
        'return_requests' => 'customer_id',
    ];

    public function up(): void
    {
        foreach (self::KEPT as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEPT as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
