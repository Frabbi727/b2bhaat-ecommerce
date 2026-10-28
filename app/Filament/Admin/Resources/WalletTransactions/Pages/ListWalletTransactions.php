<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Pages;

use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
