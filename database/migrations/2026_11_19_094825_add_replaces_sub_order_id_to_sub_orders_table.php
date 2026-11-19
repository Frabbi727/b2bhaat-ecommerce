<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA-ORD-03: a replacement is a ৳0 follow-up parcel pointing at the parcel it replaces. The original's payment to the
 * seller waits until the replacement is delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->foreignId('replaces_sub_order_id')->nullable()->after('seller_id')->constrained('sub_orders')->restrictOnDelete();
            $table->foreignId('replaces_return_request_id')->nullable()->after('replaces_sub_order_id')->unique()->constrained('return_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropUnique(['replaces_return_request_id']);
            $table->dropConstrainedForeignId('replaces_return_request_id');
            $table->dropConstrainedForeignId('replaces_sub_order_id');
        });
    }
};
