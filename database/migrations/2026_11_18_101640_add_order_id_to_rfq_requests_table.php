<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA-PRC-01: an accepted quotation records the order that used it, so a quote is used by one order only and staff
 * can trace a negotiated price to its sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('status')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
