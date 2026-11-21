<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Pages\IncomeStatementPage;
use App\Filament\Admin\Pages\KycApprovals;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\ProductModeration\ProductModerationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Accounting\SellerBalanceService;
use App\Services\Formatting\Money;
use App\Services\Reporting\DailyTotals;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $timezone = (string) config('app.display_timezone');
        $monthStart = now($timezone)->startOfMonth();

        // F2 (finance audit 2026-11-20): order value of orders placed this month, leaving out cancelled and fully
        // refunded orders. It is the value of goods sold for sellers, not the platform's income, and the card says so.
        $soldOrders = fn (): Builder => Order::query()
            ->whereDoesntHave('orderStatus', fn (Builder $q) => $q->whereIn('slug', ['cancelled', 'refunded']))
            ->whereDoesntHave('paymentStatus', fn (Builder $q) => $q->where('slug', 'refunded'));
        $salesThisMonth = (float) $soldOrders()
            ->where('created_at', '>=', $monthStart->copy()->setTimezone(config('app.timezone')))
            ->sum('total_amount');
        $sparklineSales = app(DailyTotals::class)->lastDays($soldOrders(), 'total_amount', 7)['values'];

        // The platform's own income this month, read from the books
        $incomeThisMonth = $this->incomeFromTheBooks($monthStart->toDateString(), now($timezone)->toDateString());

        // F1: what we owe sellers, never netted against what other sellers owe us
        $sellerBalances = app(SellerBalanceService::class)->split();

        $merchantCounts = SellerProfile::query()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_approved = ? THEN 1 ELSE 0 END), 0) as approved', [true])
            ->selectRaw('COALESCE(SUM(CASE WHEN is_approved = ? AND rejected_at IS NULL THEN 1 ELSE 0 END), 0) as pending', [false])
            ->first();
        $approvedMerchants = (int) $merchantCounts->approved;
        $pendingMerchants = (int) $merchantCounts->pending;

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
        $pendingReturns = ReturnRequest::whereIn('status', ReturnRequest::OPEN_STATUSES)->count();
        $totalPendingReview = $pendingMerchants + $pendingWholesale + $pendingReturns;

        $todayStart = now($timezone)->startOfDay()->setTimezone(config('app.timezone'));
        $orderCounts = Order::query()->toBase()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('COUNT(*) as last_30_days')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as today', [$todayStart])
            ->first();
        $productCounts = Product::query()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active = ? AND blocked_at IS NULL THEN 1 ELSE 0 END), 0) as live', [true])
            ->selectRaw('COALESCE(SUM(CASE WHEN blocked_at IS NOT NULL THEN 1 ELSE 0 END), 0) as blocked')
            ->first();
        $blockedProducts = (int) $productCounts->blocked;

        return [
            Stat::make('Sales this month', Money::taka($salesThisMonth))
                ->description('Value of goods sold for sellers, not our income')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->chart($sparklineSales)
                ->color('gray')
                ->url(OrderResource::getUrl('index')),

            Stat::make('Our income this month', Money::taka($incomeThisMonth))
                ->description('Commission, seller fees and delivery charges')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->url(IncomeStatementPage::getUrl()),

            Stat::make('We owe sellers', Money::taka($sellerBalances['owed_to_sellers']))
                ->description($sellerBalances['owed_by_sellers'] > 0
                    ? ($sellerBalances['sellers_owing'] === 1 ? '1 seller owes us ' : $sellerBalances['sellers_owing'].' sellers owe us ').Money::taka($sellerBalances['owed_by_sellers'])
                    : 'On hold, ready to withdraw and requested')
                ->descriptionIcon('heroicon-m-scale')
                ->color($sellerBalances['owed_by_sellers'] > 0 ? 'warning' : 'gray')
                ->url(WalletTransactionResource::getUrl('index')),

            Stat::make('Pending admin actions', (string) $totalPendingReview)
                ->description("{$pendingMerchants} sellers · {$pendingWholesale} wholesale · {$pendingReturns} returns")
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($totalPendingReview > 0 ? 'warning' : 'gray')
                ->url(KycApprovals::getUrl()),

            Stat::make('Orders, last 30 days', number_format((int) $orderCounts->last_30_days))
                ->description(((int) $orderCounts->today).' placed today')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('gray')
                ->url(OrderResource::getUrl('index')),

            Stat::make('Verified factory sellers', (string) $approvedMerchants)
                ->description($pendingMerchants > 0 ? "{$pendingMerchants} waiting for approval" : 'Trade licence verified')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($pendingMerchants > 0 ? 'warning' : 'gray')
                ->url(KycApprovals::getUrl()),

            Stat::make('Customers', number_format((int) $userCounts->customers))
                ->description(((int) $userCounts->wholesale_buyers).' approved wholesale buyers')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray')
                ->url(UserResource::getUrl('index')),

            Stat::make('Live products', number_format((int) $productCounts->live))
                ->description($blockedProducts > 0 ? "{$blockedProducts} hidden by moderation" : 'None hidden by moderation')
                ->descriptionIcon('heroicon-m-cube')
                ->color('gray')
                ->url(ProductModerationResource::getUrl('index')),
        ];
    }

    /**
     * Revenue accounts (commission, subscription, listing packs, delivery) for the period, credit minus debit, so
     * reversed commission on a refund is already taken off. One query; nothing is counted from orders.
     */
    protected function incomeFromTheBooks(string $from, string $to): float
    {
        $total = DB::table('accounting_journal_lines')
            ->join('accounting_journal_entries', 'accounting_journal_lines.journal_entry_id', '=', 'accounting_journal_entries.id')
            ->join('accounting_accounts', 'accounting_journal_lines.account_id', '=', 'accounting_accounts.id')
            ->where('accounting_accounts.type', AccountType::REVENUE->value)
            ->whereIn('accounting_journal_entries.status', [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value])
            ->whereDate('accounting_journal_entries.entry_date', '>=', $from)
            ->whereDate('accounting_journal_entries.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.credit), 0) - COALESCE(SUM(accounting_journal_lines.debit), 0) as income')
            ->value('income');

        return round((float) $total, 2);
    }
}
