<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourierParcels\Pages;

use App\Filament\Admin\Resources\CourierParcels\CourierParcelResource;
use Filament\Resources\Pages\ListRecords;

class ListCourierParcels extends ListRecords
{
    protected static string $resource = CourierParcelResource::class;
}
