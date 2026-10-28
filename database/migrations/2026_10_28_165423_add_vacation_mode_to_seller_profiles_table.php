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
            $table->boolean('is_on_vacation')->default(false)->after('is_approved');
            $table->text('vacation_message')->nullable()->after('is_on_vacation');
            $table->date('vacation_ends_at')->nullable()->after('vacation_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_on_vacation', 'vacation_message', 'vacation_ends_at']);
        });
    }
};
