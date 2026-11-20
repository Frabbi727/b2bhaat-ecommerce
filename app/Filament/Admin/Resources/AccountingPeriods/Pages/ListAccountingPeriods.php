<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingPeriods\Pages;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountingPeriods extends ListRecords
{
    protected static string $resource = AccountingPeriodResource::class;
}
