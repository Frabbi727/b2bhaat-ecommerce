<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-13: one counter row per journal-number prefix (JRN-YYYYMM-). Posting locks the row, so concurrent postings
 * wait for each other instead of picking the same number. Rows are created on first use from the highest
 * existing number, so no backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journal_sequences', function (Blueprint $table): void {
            $table->string('prefix', 32)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_sequences');
    }
};
