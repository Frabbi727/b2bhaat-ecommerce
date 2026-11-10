<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Filament\Seller\Resources\RfqRequests\RfqRequestResource;
use App\Filament\Seller\Resources\SellerWallets\SellerWalletResource;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\RfqRequest;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Services\Reporting\DailyTotals;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SellerStatsOverview extends StatsOverviewWidget
{
    /** PERF-2: refresh once a minute rather than Filament's default of every 5 seconds. */
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $sellerId = auth()->id();
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $sellerId],
            ['available_balance' => 0, 'escrow_held_balance' => 0]
        );

        $availableBalance = (float) $wallet->available_balance;
        $escrowBalance = (float) $wallet->escrow_held_balance;

        $pendingFulfillment = SubOrder::where('seller_id', $sellerId)
            ->whereHas('fulfillmentStatus', fn ($q) => $q->whereIn('slug', ['unfulfilled', 'processing']))
            ->count();

        $pendingRfqs = RfqRequest::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
            ->where('status', 'pending')
            ->count();

        // 7-day sparkline from seller sub-orders, by Dhaka day
        $sparklineEarnings = app(DailyTotals::class)->lastDays(
            SubOrder::query()
                ->where('seller_id', $sellerId)
                ->whereDoesntHave('fulfillmentStatus', fn ($q) => $q->where('slug', 'cancelled')),
            'subtotal',
            7,
        )['values'];

        return [
            Stat::make('Available Payout Balance', '৳'.number_format($availableBalance, 2))
                ->description('Settled earnings ready to withdraw')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($sparklineEarnings)
                ->color('success')
                ->url(SellerWalletResource::getUrl('index')),

            Stat::make('On hold after delivery', '৳'.number_format($escrowBalance, 2))
                ->description('Released 48 hours after delivery')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('gray')
                ->url(SellerWalletResource::getUrl('index')),

            Stat::make('Orders to dispatch', (string) $pendingFulfillment)
                ->description($pendingFulfillment > 0 ? 'Pack and book a courier' : 'All caught up')
                ->descriptionIcon('heroicon-m-truck')
                ->color($pendingFulfillment > 0 ? 'warning' : 'gray')
                ->url(SubOrderResource::getUrl('index')),

            Stat::make('Quote requests', (string) $pendingRfqs)
                ->description($pendingRfqs > 0 ? 'Wholesale buyers waiting for your price' : 'No open requests')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($pendingRfqs > 0 ? 'primary' : 'gray')
                ->url(RfqRequestResource::getUrl('index')),
        ];
    }
}
