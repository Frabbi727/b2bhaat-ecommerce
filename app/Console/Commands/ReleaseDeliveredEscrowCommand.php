<?php

namespace App\Console\Commands;

use App\Models\FulfillmentStatus;
use App\Models\ReturnRequest;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Settings\StoreConfigurationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseDeliveredEscrowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'escrow:release-delivered {--hours= : Minimum hours post-delivery required to release (defaults to dynamic store setting)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically releases held escrow funds to seller available balance for orders delivered >= holding threshold.';

    /**
     * Execute the console command.
     */
    public function handle(EscrowLedgerService $escrowService): int
    {
        $hoursOption = $this->option('hours');
        $hours = ($hoursOption !== null && $hoursOption !== '')
            ? (int) $hoursOption
            : app(StoreConfigurationService::class)->getEscrowHoldingPeriodHours();
        $thresholdTime = Carbon::now()->subHours($hours);

        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->first();
        if (! $deliveredStatus) {
            $this->error('Fulfillment status "delivered" not found in database.');

            return self::FAILURE;
        }

        // 4.1: only parcels that can still release money (a hold, no release, no refund), in batches, with just the
        // delivery log each one needs. The old version loaded every delivered parcel ever, every hour.
        $holdTypes = [WalletTransaction::TYPE_ESCROW_HOLD, WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT];
        $doneTypes = [WalletTransaction::TYPE_ESCROW_RELEASE, WalletTransaction::TYPE_ESCROW_REFUND];
        $walletRows = fn (array $types) => fn ($query) => $query->select(DB::raw(1))
            ->from('wallet_transactions')
            ->whereColumn('wallet_transactions.sub_order_id', 'sub_orders.id')
            ->whereIn('wallet_transactions.transaction_type', $types);

        $releasedCount = 0;
        $totalReleasedAmount = 0.0;

        SubOrder::query()
            ->where('fulfillment_status_id', $deliveredStatus->id)
            ->whereExists($walletRows($holdTypes))
            ->whereNotExists($walletRows($doneTypes))
            // 3.4: a return waiting for the seller or an admin, or a rejection the buyer can still escalate, keeps the money on hold
            ->withExists(['returnRequests as has_open_return' => fn ($query) => $query->where(fn ($open) => $open
                ->whereIn('status', ReturnRequest::OPEN_STATUSES)
                ->orWhere(fn ($rejected) => $rejected
                    ->where('status', ReturnRequest::STATUS_REJECTED)
                    ->whereNull('escalated_at')
                    ->where('resolved_at', '>', now()->subHours(ReturnRequest::escalationWindowHours()))))])
            ->with([
                // The first delivered entry is the delivery; later notes logged while delivered must not restart the clock
                'trackingLogs' => fn ($query) => $query->where('fulfillment_status_id', $deliveredStatus->id)->reorder('logged_at', 'asc'),
                'replacements.fulfillmentStatus',
                'replacements.trackingLogs' => fn ($query) => $query->where('fulfillment_status_id', $deliveredStatus->id)->reorder('logged_at', 'asc'),
            ])
            ->chunkById(100, function ($subOrders) use ($escrowService, $thresholdTime, &$releasedCount, &$totalReleasedAmount): void {
                foreach ($subOrders as $subOrder) {
                    // Check if delivered at least $hours ago
                    $deliveryLog = $subOrder->trackingLogs->first();
                    $deliveredAt = $deliveryLog?->logged_at ?? $subOrder->updated_at;

                    if ($subOrder->has_open_return) {
                        $this->warn("Skipped Sub-Order #{$subOrder->sub_order_number}: a return is open or can still be sent to B2Bhaat for review.");

                        continue;
                    }

                    // WA-ORD-03: a free replacement must reach the buyer first, and the buyer gets the usual window to check it
                    $replacements = $subOrder->replacements;
                    if ($replacements->contains(fn (SubOrder $replacement): bool => $replacement->fulfillmentStatus?->slug !== 'delivered')) {
                        $this->warn("Skipped Sub-Order #{$subOrder->sub_order_number}: its replacement has not been delivered yet.");

                        continue;
                    }
                    foreach ($replacements as $replacement) {
                        $replacementDeliveredAt = $replacement->trackingLogs->first()?->logged_at ?? $replacement->updated_at;
                        if ($replacementDeliveredAt->gt($deliveredAt)) {
                            $deliveredAt = $replacementDeliveredAt;
                        }
                    }

                    if ($deliveredAt->lte($thresholdTime)) {
                        $tx = $escrowService->releaseFromEscrow($subOrder);
                        if (! $tx) {
                            $this->warn("Skipped Sub-Order #{$subOrder->sub_order_number}: no escrow hold to release.");

                            continue;
                        }

                        $releasedCount++;
                        $totalReleasedAmount += (float) $tx->amount;

                        $this->info("Released ৳{$tx->amount} for Sub-Order #{$subOrder->sub_order_number} to Seller #{$subOrder->seller_id}");
                    }
                }
            });

        $this->info("Escrow release cycle completed: {$releasedCount} package(s) processed. Total released: ৳".number_format($totalReleasedAmount, 2));

        return self::SUCCESS;
    }
}
