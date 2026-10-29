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
            $table->text('rejection_reason')->nullable()->after('is_approved');
            $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('wholesale_rejection_reason')->nullable()->after('is_wholesale_approved');
            $table->timestamp('wholesale_approved_at')->nullable()->after('wholesale_rejection_reason');
            $table->timestamp('wholesale_rejected_at')->nullable()->after('wholesale_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'approved_at', 'rejected_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wholesale_rejection_reason', 'wholesale_approved_at', 'wholesale_rejected_at']);
        });
    }
};
