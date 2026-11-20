<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use App\Services\Promotions\FlashSalePricing;
use Illuminate\Console\Command;

class SyncFlashSaleStatusesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flash-sales:sync-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes flash sale campaign statuses based on the current time.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        // 0. Move enrolling campaigns whose registration deadline has passed to reviewing
        $reviewedCount = FlashSale::query()
            ->where('status', FlashSaleStatus::Enrolling)
            ->whereNotNull('registration_ends_at')
            ->where('registration_ends_at', '<=', $now)
            ->update([
                'status' => FlashSaleStatus::Reviewing,
            ]);

        // 1. Move scheduled campaigns whose start time has arrived to active
        $startedCount = FlashSale::query()
            ->where('status', FlashSaleStatus::Scheduled)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->update([
                'status' => FlashSaleStatus::Active,
            ]);

        // 2. Move scheduled or active campaigns whose end time has arrived to ended
        $endedCount = FlashSale::query()
            ->whereIn('status', [FlashSaleStatus::Scheduled, FlashSaleStatus::Active])
            ->where('ends_at', '<=', $now)
            ->update([
                'status' => FlashSaleStatus::Ended,
                'ended_at' => $now,
            ]);

        if ($startedCount > 0 || $endedCount > 0) {
            FlashSalePricing::flushResolved();
        }

        $this->info("{$startedCount} started, {$endedCount} ended.");

        if ($reviewedCount > 0) {
            $this->info("{$reviewedCount} moved to reviewing.");
        }

        return self::SUCCESS;
    }
}
