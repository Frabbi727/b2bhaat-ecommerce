<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\WalletTransactions\Pages;

use App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
