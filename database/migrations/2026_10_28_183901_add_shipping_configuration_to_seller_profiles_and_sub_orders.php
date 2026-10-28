<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->decimal('inside_dhaka_rate', 8, 2)->nullable()->after('commission_rate_percentage');
            $table->decimal('outside_dhaka_rate', 8, 2)->nullable()->after('inside_dhaka_rate');
            $table->decimal('free_shipping_threshold', 12, 2)->nullable()->after('outside_dhaka_rate');
        });

        Schema::table('sub_orders', function (Blueprint $table) {
            $table->decimal('shipping_amount', 8, 2)->default(0.00)->after('commission_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_amount');
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['inside_dhaka_rate', 'outside_dhaka_rate', 'free_shipping_threshold']);
        });
    }
};
