<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Pages;

use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\AccountingJournalEntries\Actions\CreateManualJournalAction;
use App\Filament\Admin\Resources\AccountingJournalEntries\Actions\RecordCourierRemittanceAction;
use App\Filament\Admin\Resources\AccountingJournalEntries\Actions\RecordGatewayTransferAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountingJournalEntries extends ListRecords
{
    protected static string $resource = AccountingJournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Business events post their own journals; admins add manual journals here (UX-ACC-1).
            // Money held by couriers and payment gateways is settled here (ACC-7).
            RecordCourierRemittanceAction::make(),
            RecordGatewayTransferAction::make(),
            CreateManualJournalAction::make(),
        ];
    }
}
