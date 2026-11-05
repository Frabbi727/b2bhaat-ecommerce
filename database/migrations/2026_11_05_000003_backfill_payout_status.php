<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRA-013 backfill: payouts whose note carries the old "[DISBURSED" marker become `disbursed`, the rest `requested`.
 * Touches only the new `status` column; down() sets it back to null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $payouts = DB::table('wallet_transactions')->where('transaction_type', 'payout')->whereNull('status');

        $disbursedCount = (clone $payouts)->where('reference_note', 'like', '%[DISBURSED%')->count();
        $requestedCount = (clone $payouts)->where(fn ($query) => $query
            ->whereNull('reference_note')
            ->orWhere('reference_note', 'not like', '%[DISBURSED%'))->count();

        Log::notice('PRA-013 payout status backfill', ['disbursed' => $disbursedCount, 'requested' => $requestedCount]);

        (clone $payouts)->where('reference_note', 'like', '%[DISBURSED%')->update(['status' => 'disbursed']);
        (clone $payouts)->whereNull('status')->update(['status' => 'requested']);
    }

    public function down(): void
    {
        DB::table('wallet_transactions')->where('transaction_type', 'payout')->update(['status' => null]);
    }
};
