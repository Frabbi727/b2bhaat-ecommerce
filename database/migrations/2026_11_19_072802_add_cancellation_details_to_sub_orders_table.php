<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR-12 / WA-ORD-01: a cancelled parcel records who cancelled it (buyer, seller, admin or the system), which user
 * did, why and when, so support can answer "why was my order cancelled?" and seller declines can be counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->string('cancelled_by', 20)->nullable()->after('fulfillment_status_id');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable()->after('cancelled_by_user_id');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_by', 'cancel_reason', 'cancelled_at']);
        });
    }
};
