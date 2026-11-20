<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Banner;
use App\Models\Coupon;
use Illuminate\Console\Command;

class SyncActiveCampaignsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promotions:sync-active-campaigns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes and deactivates expired promotional banners and exhausted coupons.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        // 1. Retire expired banners
        $expiredBannerCount = Banner::where('is_active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '<=', $now)
            ->update([
                'is_active' => false,
            ]);

        // 2. Deactivate expired or fully exhausted coupons
        $expiredCouponsCount = Coupon::where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->where(function ($q) use ($now) {
                    $q->whereNotNull('expires_at')->where('expires_at', '<=', $now);
                })->orWhere(function ($q) {
                    $q->whereNotNull('usage_limit_total')->whereColumn('used_count', '>=', 'usage_limit_total');
                });
            })
            ->update([
                'is_active' => false,
            ]);

        $this->info("Campaign synchronization complete: {$expiredBannerCount} banner(s) retired, {$expiredCouponsCount} coupon(s) deactivated.");

        return self::SUCCESS;
    }
}
