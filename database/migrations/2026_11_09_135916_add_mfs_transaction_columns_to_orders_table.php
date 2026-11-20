<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-3 / SEC-5: a bKash or Nagad TrxID can pay for only one order. The gateway and TrxID move into their own
 * columns with a unique index; the copy inside `shipping_address` is kept as it was.
 *
 * Existing orders are filled in. Where a TrxID was already used on more than one order, the oldest order keeps it
 * and the others are left without one; their order numbers are printed for review. No order is otherwise changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('mfs_gateway', 20)->nullable();
            $table->string('mfs_transaction_id', 50)->nullable();
            $table->unique(['mfs_gateway', 'mfs_transaction_id']);
        });

        $taken = [];
        $repeated = [];

        DB::table('orders')->orderBy('id')->select(['id', 'order_number', 'shipping_address'])->chunkById(500, function ($orders) use (&$taken, &$repeated): void {
            foreach ($orders as $order) {
                $address = json_decode((string) $order->shipping_address, true);
                $gateway = is_array($address) ? ($address['mfs_gateway'] ?? null) : null;
                $trxId = is_array($address) ? $this->normalizeTrxId($address['mfs_transaction_id'] ?? null) : null;

                if (! $gateway || ! $trxId) {
                    continue;
                }

                $key = $gateway.'|'.$trxId;
                if (isset($taken[$key])) {
                    $repeated[] = "{$order->order_number} ({$gateway} {$trxId}, first used on {$taken[$key]})";

                    continue;
                }

                $taken[$key] = $order->order_number;
                DB::table('orders')->where('id', $order->id)->update([
                    'mfs_gateway' => $gateway,
                    'mfs_transaction_id' => $trxId,
                ]);
            }
        });

        if ($repeated !== []) {
            fwrite(STDERR, 'PAY-3: '.count($repeated)." order(s) reuse a TrxID already used on an earlier order and were left without one. Review them:\n  ".implode("\n  ", $repeated)."\n");
        }
    }

    /**
     * Same rule as `Order::normalizeTrxId()`, kept here so this migration never changes with later code.
     */
    private function normalizeTrxId(mixed $trxId): ?string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', (string) $trxId));

        return $clean === '' ? null : $clean;
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['mfs_gateway', 'mfs_transaction_id']);
            $table->dropColumn(['mfs_gateway', 'mfs_transaction_id']);
        });
    }
};
