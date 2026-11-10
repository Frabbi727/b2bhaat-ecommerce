<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\WalletTransactions\Widgets;

use App\Services\Accounting\SellerLedgerStatementService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SellerStatementOverviewWidget extends StatsOverviewWidget
{
    protected array|int|null $columns = 4;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $sellerId = auth()->id();

        // ACC-19: totals come from the seller's ledger lines, not the wallet table
        $summary = app(SellerLedgerStatementService::class)->summary((int) $sellerId);

        $availableBalance = $summary['available_balance'];
        $escrowHeldBalance = $summary['escrow_held'];
        $lifetimeEarnings = $summary['net_earnings'];
        $totalWithdrawn = $summary['total_withdrawn'];
        $pendingPayouts = $summary['pending_payouts'];

        return [
            Stat::make('Available Balance', '৳'.number_format($availableBalance, 2))
                ->description($pendingPayouts > 0
                    ? 'Ready for withdrawal · ৳'.number_format($pendingPayouts, 2).' waiting to be paid'
                    : 'Ready for withdrawal')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Escrow Held Balance', '৳'.number_format($escrowHeldBalance, 2))
                ->description('Pending delivery clearing')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('warning'),

            Stat::make('Lifetime Earnings', '৳'.number_format($lifetimeEarnings, 2))
                ->description('Credited to your balance, after refunds')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),

            Stat::make('Total Withdrawn', '৳'.number_format($totalWithdrawn, 2))
                ->description('Disbursed to bank / MFS')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('gray'),
        ];
    }
}
