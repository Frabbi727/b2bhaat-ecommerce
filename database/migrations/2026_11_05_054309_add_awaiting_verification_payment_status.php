<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Manual bKash/Nagad payments wait in this status until an admin verifies the TrxID (PRA-006).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payment_statuses')->where('slug', 'awaiting_verification')->doesntExist()) {
            DB::table('payment_statuses')->insert([
                'name' => 'Awaiting Verification',
                'slug' => 'awaiting_verification',
                'badge_color' => '#F59E0B',
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $statusId = DB::table('payment_statuses')->where('slug', 'awaiting_verification')->value('id');

        if ($statusId && DB::table('orders')->where('payment_status_id', $statusId)->doesntExist()) {
            DB::table('payment_statuses')->where('id', $statusId)->delete();
        }
    }
};
