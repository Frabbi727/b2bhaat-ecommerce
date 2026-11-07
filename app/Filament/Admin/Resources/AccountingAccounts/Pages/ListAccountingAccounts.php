<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts\Pages;

use App\Filament\Admin\Resources\AccountingAccounts\AccountingAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountingAccounts extends ListRecords
{
    protected static string $resource = AccountingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
