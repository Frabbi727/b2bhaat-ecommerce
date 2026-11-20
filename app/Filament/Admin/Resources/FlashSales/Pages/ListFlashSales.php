<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Pages;

use App\Filament\Admin\Resources\FlashSales\FlashSaleResource;
use App\Filament\Admin\Resources\FlashSales\Widgets\FlashSaleStatsOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlashSales extends ListRecords
{
    protected static string $resource = FlashSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New Campaign'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FlashSaleStatsOverview::class,
        ];
    }
}
