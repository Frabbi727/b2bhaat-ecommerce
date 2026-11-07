<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountingJournalEntries extends ListRecords
{
    protected static string $resource = AccountingJournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Creation is disabled because journal entries originate strictly from business pipelines or reversals
        ];
    }
}
