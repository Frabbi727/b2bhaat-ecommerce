<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ledger row per sub-order and transaction type, so hold/release/refund can never be applied twice.
 * Payout rows have no sub-order (NULL) and are not constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('wallet_transactions')
            ->select('sub_order_id', 'transaction_type', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('sub_order_id')
            ->groupBy('sub_order_id', 'transaction_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the unique ledger index: duplicate wallet_transactions exist for (sub_order_id, transaction_type): '
                .$duplicates->map(fn ($row) => "{$row->sub_order_id}/{$row->transaction_type}×{$row->occurrences}")->implode(', ')
                .'. Reconcile them manually first; this migration changes no data.'
            );
        }

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->unique(['sub_order_id', 'transaction_type'], 'wallet_transactions_sub_order_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique('wallet_transactions_sub_order_type_unique');
        });
    }
};
