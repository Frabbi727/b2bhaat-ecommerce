<?php

namespace App\Filament\Seller\Resources\RfqRequests\Pages;

use App\Filament\Seller\Resources\RfqRequests\RfqRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRfqRequest extends EditRecord
{
    protected static string $resource = RfqRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
