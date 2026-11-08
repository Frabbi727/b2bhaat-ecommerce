<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\WalletTransactions\Widgets;

use App\Models\SellerWallet;
use App\Models\WalletTransaction;
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
        $wallet = $sellerId !== null
            ? SellerWallet::where('seller_id', $sellerId)->first()
            : null;

        $availableBalance = (float) ($wallet?->available_balance ?? 0);
        $escrowHeldBalance = (float) ($wallet?->escrow_held_balance ?? 0);

        $lifetimeEarnings = $wallet !== null
            ? (float) WalletTransaction::where('seller_wallet_id', $wallet->id)
                ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
                ->sum('amount')
            : 0.0;

        $totalWithdrawn = $wallet !== null
            ? (float) WalletTransaction::where('seller_wallet_id', $wallet->id)
                ->where('transaction_type', WalletTransaction::TYPE_PAYOUT)
                ->where('status', WalletTransaction::STATUS_DISBURSED)
                ->sum('amount')
            : 0.0;

        return [
            Stat::make('Available Balance', '৳'.number_format($availableBalance, 2))
                ->description('Ready for withdrawal')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Escrow Held Balance', '৳'.number_format($escrowHeldBalance, 2))
                ->description('Pending delivery clearing')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('warning'),

            Stat::make('Lifetime Earnings', '৳'.number_format($lifetimeEarnings, 2))
                ->description('Total sales credited')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),

            Stat::make('Total Withdrawn', '৳'.number_format($totalWithdrawn, 2))
                ->description('Disbursed to bank / MFS')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('gray'),
        ];
    }
}
