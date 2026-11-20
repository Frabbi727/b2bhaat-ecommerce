<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Widgets;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\OrderItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FlashSaleStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $activeCount = FlashSale::query()->live()->count();
        $scheduledCount = FlashSale::query()->upcoming()->count();
        $pausedCount = FlashSale::query()->where('status', FlashSaleStatus::Paused)->count();
        $endedCount = FlashSale::query()->where(function ($q): void {
            $q->where('status', FlashSaleStatus::Ended)
                ->orWhere(fn ($sq) => $sq->whereIn('status', [FlashSaleStatus::Scheduled, FlashSaleStatus::Active])->where('ends_at', '<=', now()));
        })->count();

        $liveProductsCount = FlashSaleProduct::query()
            ->whereHas('flashSale', fn ($q) => $q->live())
            ->count();

        $recentItemsQuery = OrderItem::query()
            ->whereNotNull('flash_sale_product_id')
            ->whereHas('subOrder.fulfillmentStatus', fn ($q) => $q->where('slug', '!=', 'cancelled'))
            ->where('created_at', '>=', now()->subDays(30));

        $unitsSold30d = (int) $recentItemsQuery->sum('quantity');
        $revenue30d = (float) $recentItemsQuery->sum('total_price');

        return [
            Stat::make('Active Campaigns', (string) $activeCount)
                ->description("{$liveProductsCount} discounted products live")
                ->color('success'),

            Stat::make('Scheduled', (string) $scheduledCount)
                ->description('Upcoming sales')
                ->color('info'),

            Stat::make('Paused / Ended', "{$pausedCount} / {$endedCount}")
                ->description('Inactive campaigns')
                ->color('gray'),

            Stat::make('30-Day Campaign Revenue', '৳'.number_format($revenue30d, 2))
                ->description("{$unitsSold30d} units sold in 30 days")
                ->color('primary'),
        ];
    }
}
