<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Pages;

use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\WalletTransaction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    public function getSubheading(): ?string
    {
        return 'Pay each request from the seller’s chosen account, then record the TrxID. Rejecting returns the money to their wallet.';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $waiting = WalletTransaction::where('transaction_type', 'payout')->where('status', WalletTransaction::STATUS_REQUESTED)->count();

        return [
            'requests' => Tab::make('Waiting to be paid')
                ->badge($waiting ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('transaction_type', 'payout')->where('status', WalletTransaction::STATUS_REQUESTED)),
            'payouts' => Tab::make('All payouts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('transaction_type', 'payout')),
            'ledger' => Tab::make('Full ledger'),
        ];
    }
}
