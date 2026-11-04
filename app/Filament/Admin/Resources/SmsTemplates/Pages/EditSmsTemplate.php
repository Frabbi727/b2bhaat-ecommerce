<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SmsTemplates\Pages;

use App\Filament\Admin\Resources\SmsTemplates\SmsTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditSmsTemplate extends EditRecord
{
    protected static string $resource = SmsTemplateResource::class;
}
