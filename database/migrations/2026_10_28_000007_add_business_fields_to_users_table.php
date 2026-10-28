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
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_name', 255)->nullable()->after('name');
            $table->string('trade_license_number', 128)->nullable()->after('phone');
            $table->string('tax_id_number', 128)->nullable()->after('trade_license_number');
            $table->boolean('is_wholesale_approved')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'business_name',
                'trade_license_number',
                'tax_id_number',
                'is_wholesale_approved',
            ]);
        });
    }
};
