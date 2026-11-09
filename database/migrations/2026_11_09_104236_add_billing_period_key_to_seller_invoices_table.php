<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MON-10 / ACC-10: a subscription can be billed only once for each period. Renewal bills get the key
 * `renewal:{subscription}:{due date}`, plan-change bills `plan_change:{subscription}`, and a unique index enforces it.
 *
 * Existing renewal bills are keyed too. Where a period was already billed more than once, the key goes on one bill
 * (a paid one first, else the oldest) and the others are left without a key. No bill is changed or removed; the
 * number left unkeyed is printed so the accountant can review them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_invoices', function (Blueprint $table): void {
            $table->string('billing_period_key', 100)->nullable()->unique();
        });

        $bills = DB::table('seller_invoices')
            ->where('invoice_type', 'subscription_fee')
            ->whereNotNull('subscription_id')
            ->orderByRaw("CASE WHEN status = 'paid' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['id', 'subscription_id', 'due_date', 'metadata']);

        $taken = [];
        $duplicates = 0;

        foreach ($bills as $bill) {
            $metadata = is_string($bill->metadata) ? json_decode($bill->metadata, true) : (array) $bill->metadata;

            // Plan-change bills raised before the MON-4 fix pointed at the old subscription; leave them unkeyed.
            if (is_array($metadata) && array_key_exists('target_plan_id', $metadata)) {
                continue;
            }

            $key = 'renewal:'.$bill->subscription_id.':'.substr((string) $bill->due_date, 0, 10);

            if (isset($taken[$key])) {
                $duplicates++;

                continue;
            }

            $taken[$key] = true;
            DB::table('seller_invoices')->where('id', $bill->id)->update(['billing_period_key' => $key]);
        }

        if ($duplicates > 0) {
            fwrite(STDERR, "MON-10: {$duplicates} renewal bill(s) repeat a period that was already billed and were left without a billing period key. Review them in Seller Invoices.\n");
        }
    }

    public function down(): void
    {
        Schema::table('seller_invoices', function (Blueprint $table): void {
            $table->dropUnique(['billing_period_key']);
            $table->dropColumn('billing_period_key');
        });
    }
};
