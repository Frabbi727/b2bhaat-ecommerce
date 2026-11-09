<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-2: bKash and Nagad orders waiting for payment verification remind admins after a while and are cancelled as
 * "payment not confirmed" if nobody checks them in time. Adds the reminder timestamp and that payment status.
 * No existing order is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('verification_reminded_at')->nullable();
        });

        if (! DB::table('payment_statuses')->where('slug', 'expired')->exists()) {
            DB::table('payment_statuses')->insert([
                'name' => 'Not Confirmed',
                'slug' => 'expired',
                'badge_color' => '#9CA3AF',
                'is_terminal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The status is removed only while no order uses it.
     */
    public function down(): void
    {
        $statusId = DB::table('payment_statuses')->where('slug', 'expired')->value('id');
        if ($statusId !== null && ! DB::table('orders')->where('payment_status_id', $statusId)->exists()) {
            DB::table('payment_statuses')->where('id', $statusId)->delete();
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('verification_reminded_at');
        });
    }
};
