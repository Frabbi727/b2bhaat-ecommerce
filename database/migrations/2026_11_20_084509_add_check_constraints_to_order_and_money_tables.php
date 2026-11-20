<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit 1.6 (AR-09): the database refuses an order line with no quantity or a negative price, and a status or
 * type the code never writes, whatever code path tries it. The value lists are copied here on purpose: a new
 * status needs its own migration, so the database never silently accepts a typo.
 *
 * PostgreSQL only (SQLite can't add a CHECK to an existing table; the application rules cover it there). Each
 * constraint is added NOT VALID, so it guards new rows at once, then validated only when no existing row breaks
 * it. If old rows do break it, the deploy still succeeds, the constraint still guards new rows, and the offending
 * count is logged so the rows can be fixed and the constraint validated by hand.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{table: string, check: string}>
     */
    protected function constraints(): array
    {
        $in = fn (string $column, array $values, bool $nullable = false): string => ($nullable ? "{$column} IS NULL OR " : '')
            ."{$column} IN ('".implode("', '", $values)."')";

        return [
            'order_items_quantity_positive' => ['table' => 'order_items', 'check' => 'quantity > 0'],
            'order_items_unit_price_not_negative' => ['table' => 'order_items', 'check' => 'unit_price >= 0'],
            'order_items_total_price_not_negative' => ['table' => 'order_items', 'check' => 'total_price >= 0'],

            'refunds_status_known' => ['table' => 'refunds', 'check' => $in('status', ['pending', 'paid'])],

            'return_requests_status_known' => ['table' => 'return_requests', 'check' => $in('status', ['pending', 'approved', 'rejected', 'resolved'])],
            'return_requests_requested_action_known' => ['table' => 'return_requests', 'check' => $in('requested_action', ['refund', 'replacement'])],

            'wallet_transactions_status_known' => ['table' => 'wallet_transactions', 'check' => $in('status', ['requested', 'disbursed', 'rejected'], nullable: true)],
            'wallet_transactions_type_known' => ['table' => 'wallet_transactions', 'check' => $in('transaction_type', [
                'escrow_hold', 'escrow_release', 'escrow_refund', 'payout', 'escrow_hold_adjustment', 'return_reversal',
                'cod_self_collected', 'cod_self_collected_reversal', 'payout_reversal', 'cod_deficit_payment',
                'partial_return_held', 'partial_return_released', 'partial_return_cod', 'cod_return_refund',
                'subscription_payment',
            ])],

            'seller_invoices_status_known' => ['table' => 'seller_invoices', 'check' => $in('status', ['unpaid', 'paid', 'pending_verification', 'void', 'refunded'])],
            'seller_invoices_type_known' => ['table' => 'seller_invoices', 'check' => $in('invoice_type', ['subscription_fee', 'listing_pack', 'cod_commission_deficit', 'manual_fee'])],
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints() as $name => ['table' => $table, 'check' => $check]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check}) NOT VALID");

            $breaking = (int) DB::selectOne("SELECT count(*) AS n FROM {$table} WHERE NOT ({$check})")->n;

            if ($breaking === 0) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
            } else {
                Log::warning("Constraint {$name} guards new rows only: {$breaking} existing {$table} rows break it. Fix them, then run: ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints() as $name => ['table' => $table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
