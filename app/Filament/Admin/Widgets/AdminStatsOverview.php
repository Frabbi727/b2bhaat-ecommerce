<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\KycApprovals;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\ProductModeration\ProductModerationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marketplace health at a glance. Every figure is a live aggregate query; the first row is money and the work
 * waiting on an admin, the second row is the size of the marketplace.
 */
class AdminStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $paidOrders = fn (): Builder => Order::whereHas('paymentStatus', fn (Builder $q) => $q->where('slug', 'paid'));

        $totalGmv = (float) $paidOrders()->sum('total_amount');
        $totalEscrow = (float) SellerWallet::sum('escrow_held_balance');
        $approvedMerchants = SellerProfile::where('is_approved', true)->count();

        $pendingMerchants = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
        $pendingWholesale = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'))
            ->count();
        $pendingReturns = ReturnRequest::where('status', ReturnRequest::STATUS_PENDING)->count();
        $totalPendingReview = $pendingMerchants + $pendingWholesale + $pendingReturns;

        // 7-day GMV sparkline: one grouped query instead of loading every paid order
        $since = now()->subDays(6)->startOfDay();
        $dailyGmv = $paidOrders()
            ->where('created_at', '>=', $since)
            ->get(['total_amount', 'created_at'])
            ->groupBy(fn (Order $order): string => $order->created_at->format('Y-m-d'))
            ->map(fn ($orders): float => (float) $orders->sum('total_amount'));
        $sparklineGmv = collect(range(6, 0))
            ->map(fn (int $i): float => $dailyGmv[now()->subDays($i)->format('Y-m-d')] ?? 0.0)
            ->all();

        $ordersLast30Days = Order::where('created_at', '>=', now()->subDays(30))->count();
        $ordersToday = Order::where('created_at', '>=', now()->startOfDay())->count();
        $customers = User::whereHas('role', fn (Builder $q) => $q->whereIn('slug', ['retail_buyer', 'wholesale_buyer']))->count();
        $wholesaleBuyers = User::where('is_wholesale_approved', true)->count();
        $liveProducts = Product::where('is_active', true)->whereNull('blocked_at')->count();
        $blockedProducts = Product::whereNotNull('blocked_at')->count();

        return [
            Stat::make('Platform Settled GMV', '৳'.number_format($totalGmv, 2))
                ->description('Paid order value, all time')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($sparklineGmv)
                ->color('success')
                ->url(OrderResource::getUrl('index')),

            Stat::make('Active Escrow Reserve', '৳'.number_format($totalEscrow, 2))
                ->description('Held until the dispute window closes')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('gray')
                ->url(WalletTransactionResource::getUrl('index')),

            Stat::make('Verified Factory Sellers', (string) $approvedMerchants)
                ->description($pendingMerchants > 0 ? "{$pendingMerchants} waiting for approval" : 'Trade licence verified')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($pendingMerchants > 0 ? 'warning' : 'gray')
                ->url(KycApprovals::getUrl()),

            Stat::make('Pending Admin Actions', (string) $totalPendingReview)
                ->description("{$pendingMerchants} sellers · {$pendingWholesale} wholesale · {$pendingReturns} disputes")
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($totalPendingReview > 0 ? 'warning' : 'gray')
                ->url(KycApprovals::getUrl()),

            Stat::make('Orders, last 30 days', number_format($ordersLast30Days))
                ->description("{$ordersToday} placed today")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('gray')
                ->url(OrderResource::getUrl('index')),

            Stat::make('Customers', number_format($customers))
                ->description("{$wholesaleBuyers} approved wholesale buyers")
                ->descriptionIcon('heroicon-m-users')
                ->color('gray')
                ->url(UserResource::getUrl('index')),

            Stat::make('Live products', number_format($liveProducts))
                ->description($blockedProducts > 0 ? "{$blockedProducts} hidden by moderation" : 'None hidden by moderation')
                ->descriptionIcon('heroicon-m-cube')
                ->color('gray')
                ->url(ProductModerationResource::getUrl('index')),

            Stat::make('Open disputes', (string) $pendingReturns)
                ->description($pendingReturns > 0 ? 'Returns waiting for a decision' : 'Nothing waiting')
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color($pendingReturns > 0 ? 'danger' : 'gray'),
        ];
    }
}
