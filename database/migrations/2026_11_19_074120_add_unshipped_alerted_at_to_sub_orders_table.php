<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR-16: when the seller and admins were told a parcel is still unshipped, so each parcel is flagged once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->timestamp('unshipped_alerted_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropColumn('unshipped_alerted_at');
        });
    }
};
