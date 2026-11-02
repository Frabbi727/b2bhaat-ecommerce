<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Models\RfqRequest;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SellerStatsOverview extends StatsOverviewWidget
{
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

        // 7-day sparkline from seller sub-orders
        $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->format('Y-m-d'));
        $recentSubOrders = SubOrder::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->get();

        $sparklineEarnings = [];
        foreach ($days as $dateStr) {
            $sparklineEarnings[] = (float) $recentSubOrders->filter(fn ($s) => $s->created_at->format('Y-m-d') === $dateStr)->sum('subtotal');
        }

        return [
            Stat::make('Available Payout Balance', '৳'.number_format($availableBalance, 2))
                ->description('Settled earnings ready to withdraw')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($sparklineEarnings)
                ->color('success'),

            Stat::make('Escrow In-Transit Reserve', '৳'.number_format($escrowBalance, 2))
                ->description('Held until courier delivery confirmed')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('indigo'),

            Stat::make('Pending Dispatch', (string) $pendingFulfillment)
                ->description('Packages awaiting courier pickup')
                ->descriptionIcon('heroicon-m-truck')
                ->color($pendingFulfillment > 0 ? 'warning' : 'gray'),

            Stat::make('Wholesale RFQ Inquiries', (string) $pendingRfqs)
                ->description('Pending wholesale price quotes')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($pendingRfqs > 0 ? 'primary' : 'gray'),
        ];
    }
}
