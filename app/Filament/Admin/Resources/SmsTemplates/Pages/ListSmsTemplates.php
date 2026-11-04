<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SmsTemplates\Pages;

use App\Filament\Admin\Resources\SmsTemplates\SmsTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsTemplates extends ListRecords
{
    protected static string $resource = SmsTemplateResource::class;
}
