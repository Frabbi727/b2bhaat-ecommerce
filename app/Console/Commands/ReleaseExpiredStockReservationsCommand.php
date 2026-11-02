<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentStatus;
use App\Services\Inventory\StockReservationService;
use App\Services\Settings\StoreConfigurationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredStockReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:release-expired-reservations {--minutes= : Override reservation TTL in minutes (defaults to dynamic store setting)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Releases reserved stock back to inventory for pending orders where payment was not completed within the TTL window.';

    /**
     * Execute the console command.
     */
    public function handle(
        StockReservationService $stockService,
        StoreConfigurationService $configService
    ): int {
        $minutesOption = $this->option('minutes');
        $ttlMinutes = ($minutesOption !== null && $minutesOption !== '')
            ? (int) $minutesOption
            : $configService->getStockReservationTtl();

        $thresholdTime = Carbon::now()->subMinutes($ttlMinutes);

        $pendingOrderStatus = OrderStatus::where('slug', 'pending')->first();
        $unpaidPaymentStatus = PaymentStatus::where('slug', 'unpaid')->first();
        $cancelledOrderStatus = OrderStatus::where('slug', 'cancelled')->first();
        $cancelledFulfillmentStatus = FulfillmentStatus::where('slug', 'cancelled')->first();

        if (! $pendingOrderStatus || ! $unpaidPaymentStatus || ! $cancelledOrderStatus || ! $cancelledFulfillmentStatus) {
            $this->error('Required order or fulfillment statuses not found in database.');

            return self::FAILURE;
        }

        // Query pending unpaid orders created before threshold
        $expiredOrders = Order::where('order_status_id', $pendingOrderStatus->id)
            ->where('payment_status_id', $unpaidPaymentStatus->id)
            ->where('created_at', '<=', $thresholdTime)
            ->with(['subOrders.items.variation'])
            ->get();

        $cancelledOrdersCount = 0;
        $totalItemsRestored = 0;

        foreach ($expiredOrders as $order) {
            DB::transaction(function () use (
                $order,
                $stockService,
                $cancelledOrderStatus,
                $cancelledFulfillmentStatus,
                $ttlMinutes,
                &$cancelledOrdersCount,
                &$totalItemsRestored
            ) {
                foreach ($order->subOrders as $subOrder) {
                    $stockItems = [];
                    foreach ($subOrder->items as $item) {
                        if ($item->variation_id) {
                            $stockItems[] = [
                                'variation_id' => $item->variation_id,
                                'quantity' => $item->quantity,
                            ];
                            $totalItemsRestored += $item->quantity;
                        }
                    }

                    if (! empty($stockItems)) {
                        $stockService->releaseStock($stockItems);
                    }

                    $subOrder->update([
                        'fulfillment_status_id' => $cancelledFulfillmentStatus->id,
                    ]);

                    OrderTrackingLog::create([
                        'sub_order_id' => $subOrder->id,
                        'fulfillment_status_id' => $cancelledFulfillmentStatus->id,
                        'title' => 'Stock Reservation Expired',
                        'description' => "Payment not completed within {$ttlMinutes}-minute checkout window. Reserved items restored to catalog inventory.",
                        'hub_location' => 'Inventory Control Hub',
                        'logged_at' => now(),
                    ]);
                }

                $order->update([
                    'order_status_id' => $cancelledOrderStatus->id,
                ]);

                $cancelledOrdersCount++;
            });
        }

        $this->info("Expired stock reservation cleanup complete: {$cancelledOrdersCount} order(s) cancelled, {$totalItemsRestored} item unit(s) restored to stock.");

        return self::SUCCESS;
    }
}
