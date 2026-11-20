<?php

use App\Services\Accounting\SettlementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AR-15: one transfer reference settles one refund per method. reference_key is the reference without spaces,
 * dashes or case ("bk rf 7" = "BK-RF-7"), unique per method. An older duplicate keeps its reference but gets no key,
 * so the index can be built; new refunds can't repeat it.
 *
 * AR-05: an order was marked "Refunded" when its refunds were only recorded, not paid. Such orders become "Refund
 * pending"; RefundService moves them on to "Refunded" or "Partially refunded" as the refunds are paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('reference_key', 100)->nullable()->after('reference');
            $table->unique(['method', 'reference_key']);
        });

        $seen = [];
        DB::table('refunds')->whereNotNull('reference')->orderBy('id')->get(['id', 'method', 'reference'])
            ->each(function (object $refund) use (&$seen): void {
                $key = SettlementService::referenceKey($refund->reference);
                if ($key === '' || isset($seen[$refund->method][$key])) {
                    return;
                }
                $seen[$refund->method][$key] = true;
                DB::table('refunds')->where('id', $refund->id)->update(['reference_key' => $key]);
            });

        // Existing installs get the new statuses here; a fresh install gets them from SystemLookupSeeder
        if (DB::table('payment_statuses')->exists()) {
            foreach ([['Refund Pending', 'refund_pending', '#F97316'], ['Partially Refunded', 'partially_refunded', '#8B5CF6']] as [$name, $slug, $color]) {
                if (! DB::table('payment_statuses')->where('slug', $slug)->exists()) {
                    DB::table('payment_statuses')->insert([
                        'name' => $name, 'slug' => $slug, 'badge_color' => $color, 'is_terminal' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        $status = fn (string $slug) => DB::table('payment_statuses')->where('slug', $slug)->value('id');
        if ($refundedId = $status('refunded')) {
            DB::table('orders')->where('payment_status_id', $refundedId)->orderBy('id')->get(['id'])
                ->each(function (object $order) use ($status): void {
                    $refunds = DB::table('refunds')->where('order_id', $order->id);
                    if ((clone $refunds)->where('status', 'pending')->exists()) {
                        DB::table('orders')->where('id', $order->id)->update(['payment_status_id' => $status('refund_pending')]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['method', 'reference_key']);
            $table->dropColumn('reference_key');
        });
    }
};
