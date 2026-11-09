<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-7: records which courier remittance settled a COD consignment, so the same parcel can never be settled twice.
 * Both columns start empty; no data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table): void {
            $table->timestamp('courier_settled_at')->nullable();
            $table->foreignId('courier_settlement_journal_id')
                ->nullable()
                ->constrained('accounting_journal_entries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courier_settlement_journal_id');
            $table->dropColumn('courier_settled_at');
        });
    }
};
