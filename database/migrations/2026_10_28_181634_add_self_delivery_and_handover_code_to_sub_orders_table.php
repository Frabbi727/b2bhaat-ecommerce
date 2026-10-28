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
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->boolean('is_self_delivered')->default(false)->after('commission_amount');
            $table->string('handover_code', 16)->nullable()->after('is_self_delivered');
            $table->timestamp('handover_confirmed_at')->nullable()->after('handover_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            $table->dropColumn(['is_self_delivered', 'handover_code', 'handover_confirmed_at']);
        });
    }
};
