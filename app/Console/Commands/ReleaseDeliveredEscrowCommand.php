<?php

namespace App\Console\Commands;

use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ReleaseDeliveredEscrowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'escrow:release-delivered {--hours=48 : Minimum hours post-delivery required to release}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically releases held escrow funds to seller available balance for orders delivered >= 48 hours ago.';

    /**
     * Execute the console command.
     */
    public function handle(EscrowLedgerService $escrowService): int
    {
        $hours = (int) $this->option('hours');
        $thresholdTime = Carbon::now()->subHours($hours);

        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->first();
        if (! $deliveredStatus) {
            $this->error('Fulfillment status "delivered" not found in database.');

            return self::FAILURE;
        }

        // Find delivered sub-orders
        $subOrders = SubOrder::where('fulfillment_status_id', $deliveredStatus->id)
            ->with(['trackingLogs' => function ($query) use ($deliveredStatus) {
                $query->where('fulfillment_status_id', $deliveredStatus->id)->latest('logged_at');
            }])
            ->get();

        $releasedCount = 0;
        $totalReleasedAmount = 0.0;

        foreach ($subOrders as $subOrder) {
            // Check if already released
            $alreadyReleased = WalletTransaction::where('sub_order_id', $subOrder->id)
                ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
                ->exists();

            if ($alreadyReleased) {
                continue;
            }

            // Check if delivered at least $hours ago
            $deliveryLog = $subOrder->trackingLogs->first();
            $deliveredAt = $deliveryLog?->logged_at ?? $subOrder->updated_at;

            if ($deliveredAt->lte($thresholdTime)) {
                $tx = $escrowService->releaseFromEscrow($subOrder);
                $releasedCount++;
                $totalReleasedAmount += (float) $tx->amount;

                $this->info("Released ৳{$tx->amount} for Sub-Order #{$subOrder->sub_order_number} to Seller #{$subOrder->seller_id}");
            }
        }

        $this->info("Escrow release cycle completed: {$releasedCount} package(s) processed. Total released: ৳".number_format($totalReleasedAmount, 2));

        return self::SUCCESS;
    }
}
