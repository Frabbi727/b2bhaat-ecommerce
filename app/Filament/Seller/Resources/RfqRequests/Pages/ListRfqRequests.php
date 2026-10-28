<?php

namespace App\Filament\Seller\Resources\RfqRequests\Pages;

use App\Filament\Seller\Resources\RfqRequests\RfqRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRfqRequests extends ListRecords
{
    protected static string $resource = RfqRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
