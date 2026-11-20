<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\FlashSales\Pages;

use App\Filament\Seller\Resources\FlashSales\FlashSaleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFlashSale extends ViewRecord
{
    protected static string $resource = FlashSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FlashSaleResource::getSubmitProductAction(),
        ];
    }
}
