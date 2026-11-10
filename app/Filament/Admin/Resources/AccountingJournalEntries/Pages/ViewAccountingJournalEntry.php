<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\AccountingJournalEntries\Tables\AccountingJournalEntriesTable;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

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
            AccountingJournalEntriesTable::undoSettlementAction(),
        ];
    }

    /**
     * PERF-3: the page lists every line with its account, seller, customer and order, so load them all at once.
     * A view page never fills a form, so this has to happen when the record is resolved.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing([
            'lines.account',
            'lines.seller',
            'lines.customer',
            'lines.order',
            'lines.subOrder',
            'reversalJournal',
            'reversedEntries',
            'period',
            'createdByUser',
            'reference',
        ]);
    }
}
