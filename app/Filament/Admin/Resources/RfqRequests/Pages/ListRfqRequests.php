<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RfqRequests\Pages;

use App\Filament\Admin\Resources\RfqRequests\RfqRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListRfqRequests extends ListRecords
{
    protected static string $resource = RfqRequestResource::class;
}
