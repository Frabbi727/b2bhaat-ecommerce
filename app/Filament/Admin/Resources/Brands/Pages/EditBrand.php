<?php

namespace App\Filament\Admin\Resources\Brands\Pages;

use App\Filament\Admin\Resources\Brands\BrandResource;
use App\Filament\Admin\Resources\Brands\Tables\BrandsTable;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BrandsTable::deleteAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
