<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Banner;
use App\Models\Coupon;
use App\Models\Product;
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
    protected $description = 'Synchronizes and deactivates expired flash sales, promotional banners, and exhausted coupons.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        // 1. Conclude expired flash sales
        $expiredFlashCount = Product::where('is_flash_sale', true)
            ->whereNotNull('flash_sale_end')
            ->where('flash_sale_end', '<=', $now)
            ->update([
                'is_flash_sale' => false,
            ]);

        // 2. Retire expired banners
        $expiredBannerCount = Banner::where('is_active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '<=', $now)
            ->update([
                'is_active' => false,
            ]);

        // 3. Deactivate expired or fully exhausted coupons
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

        $this->info("Campaign synchronization complete: {$expiredFlashCount} flash sale(s) concluded, {$expiredBannerCount} banner(s) retired, {$expiredCouponsCount} coupon(s) deactivated.");

        return self::SUCCESS;
    }
}
