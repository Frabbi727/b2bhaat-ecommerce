<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F3 (finance audit 2026-11-20): buyers pay by "Send Money" to the platform's own bKash/Nagad number, and sellers
 * are paid from it, so account 1030 is money the platform holds, not money in transit at a gateway. Only the name
 * and description change; the code, slug and every posted line stay as they are. A real payment gateway (roadmap
 * 3.1) gets its own clearing account when it is chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounting_accounts')->where('system_slug', 'gateway_clearing')->update([
            'name' => 'bKash / Nagad Wallet',
            'description' => 'Money in the platform\'s bKash and Nagad numbers: buyer payments in, seller payouts and refunds out',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('accounting_accounts')->where('system_slug', 'gateway_clearing')->update([
            'name' => 'Payment Gateway Clearing',
            'description' => 'Payment gateway uncleared collections',
            'updated_at' => now(),
        ]);
    }
};
