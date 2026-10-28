<?php

namespace App\Filament\Seller\Resources\SellerWallets\Pages;

use App\Filament\Seller\Resources\SellerWallets\SellerWalletResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSellerWallet extends EditRecord
{
    protected static string $resource = SellerWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
