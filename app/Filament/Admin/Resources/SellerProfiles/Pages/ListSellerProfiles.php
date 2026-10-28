<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Pages;

use App\Filament\Admin\Resources\SellerProfiles\SellerProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSellerProfiles extends ListRecords
{
    protected static string $resource = SellerProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
