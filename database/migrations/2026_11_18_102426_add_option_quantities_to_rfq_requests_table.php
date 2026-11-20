<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA-PRC-03: on a product with options, an RFQ records how many units of each option the buyer wants
 * ({variation_id: quantity}); target_quantity is their sum. Empty for a product without options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->json('option_quantities')->nullable()->after('target_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropColumn('option_quantities');
        });
    }
};
