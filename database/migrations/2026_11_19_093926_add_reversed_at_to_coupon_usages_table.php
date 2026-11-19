<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR-04: when a coupon use was given back because its order ended fully cancelled. A given-back use no longer counts
 * toward the coupon's per-customer limit; the row is kept as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropColumn('reversed_at');
        });
    }
};
