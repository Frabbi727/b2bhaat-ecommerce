<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalGmv = (float) Order::whereHas('paymentStatus', fn ($q) => $q->where('slug', 'paid'))
            ->sum('total_amount');

        $totalEscrow = SellerWallet::sum('escrow_held_balance');

        $approvedMerchants = SellerProfile::where('is_approved', true)->count();

        $pendingMerchants = SellerProfile::where('is_approved', false)->count();
        $pendingReturns = ReturnRequest::where('status', 'pending')->count();
        $totalPendingReview = $pendingMerchants + $pendingReturns;

        return [
            Stat::make('Platform Settled GMV', '৳'.number_format($totalGmv, 2))
                ->description('Paid & settled marketplace volume')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Active Escrow Reserve', '৳'.number_format($totalEscrow, 2))
                ->description('Funds held for in-transit parcels')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('indigo'),

            Stat::make('Verified Factory Sellers', (string) $approvedMerchants)
                ->description('Govt. trade license approved')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('emerald'),

            Stat::make('Pending Admin Actions', (string) $totalPendingReview)
                ->description("{$pendingMerchants} merchant KYC, {$pendingReturns} disputes")
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($totalPendingReview > 0 ? 'warning' : 'gray'),
        ];
    }
}
