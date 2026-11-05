<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReturnRequests\Pages;

use App\Filament\Admin\Resources\ReturnRequests\ReturnRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListReturnRequests extends ListRecords
{
    protected static string $resource = ReturnRequestResource::class;
}
