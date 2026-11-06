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

        return self::SUCCESS;
    }
}
