<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Pages;

use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWalletTransaction extends EditRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
