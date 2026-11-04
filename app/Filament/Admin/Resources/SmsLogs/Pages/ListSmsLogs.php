<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SmsLogs\Pages;

use App\Filament\Admin\Resources\SmsLogs\SmsLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsLogs extends ListRecords
{
    protected static string $resource = SmsLogResource::class;
}
