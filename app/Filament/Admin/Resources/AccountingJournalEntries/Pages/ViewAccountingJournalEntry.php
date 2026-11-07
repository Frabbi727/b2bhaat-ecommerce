<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\AccountingJournalEntries\Tables\AccountingJournalEntriesTable;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountingJournalEntry extends ViewRecord
{
    protected static string $resource = AccountingJournalEntryResource::class;

    public function getTitle(): string
    {
        return 'Journal Entry '.$this->getRecord()->journal_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            AccountingJournalEntriesTable::reverseAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->getRecord()->loadMissing([
            'lines.account',
            'lines.seller',
            'lines.customer',
            'lines.order',
            'lines.subOrder',
            'reversalJournal',
            'reversedEntries',
            'period',
            'createdByUser',
        ]);

        return $data;
    }
}
