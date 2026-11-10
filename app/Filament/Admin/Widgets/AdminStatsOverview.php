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
use App\Services\Reporting\DailyTotals;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marketplace health at a glance. Every figure is a live aggregate query; the first row is money and the work
 * waiting on an admin, the second row is the size of the marketplace.
 */
class AdminStatsOverview extends StatsOverviewWidget
{
    /** PERF-2: refresh once a minute rather than Filament's default of every 5 seconds. */
    protected ?string $pollingInterval = '60s';

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
        // PERF-2: one query for the buyer counts
        $userCounts = User::query()->toBase()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN is_wholesale_approved = ? AND wholesale_rejected_at IS NULL AND (trade_license_number IS NOT NULL OR business_name IS NOT NULL) THEN 1 ELSE 0 END), 0) as pending_wholesale',
                [false],
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN is_wholesale_approved = ? THEN 1 ELSE 0 END), 0) as wholesale_buyers', [true])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN role_id IN (SELECT id FROM roles WHERE slug IN ('retail_buyer', 'wholesale_buyer')) THEN 1 ELSE 0 END), 0) as customers",
            )
            ->first();
        $pendingWholesale = (int) $userCounts->pending_wholesale;
        $pendingReturns = ReturnRequest::where('status', ReturnRequest::STATUS_PENDING)->count();
        $totalPendingReview = $pendingMerchants + $pendingWholesale + $pendingReturns;

        // 7-day GMV sparkline, by Dhaka day
        $sparklineGmv = app(DailyTotals::class)->lastDays($paidOrders(), 'total_amount', 7)['values'];

        $todayStart = now(config('app.display_timezone'))->startOfDay()->setTimezone(config('app.timezone'));
        $orderCounts = Order::query()->toBase()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('COUNT(*) as last_30_days')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as today', [$todayStart])
            ->first();
        $ordersLast30Days = (int) $orderCounts->last_30_days;
        $ordersToday = (int) $orderCounts->today;
        $customers = (int) $userCounts->customers;
        $wholesaleBuyers = (int) $userCounts->wholesale_buyers;
        $productCounts = Product::query()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active = ? AND blocked_at IS NULL THEN 1 ELSE 0 END), 0) as live', [true])
            ->selectRaw('COALESCE(SUM(CASE WHEN blocked_at IS NOT NULL THEN 1 ELSE 0 END), 0) as blocked')
            ->first();
        $liveProducts = (int) $productCounts->live;
        $blockedProducts = (int) $productCounts->blocked;

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
