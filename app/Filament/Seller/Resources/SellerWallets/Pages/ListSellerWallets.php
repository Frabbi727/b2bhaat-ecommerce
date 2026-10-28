<?php

namespace App\Filament\Seller\Resources\SellerWallets\Pages;

use App\Filament\Seller\Resources\SellerWallets\SellerWalletResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSellerWallets extends ListRecords
{
    protected static string $resource = SellerWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
