<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\SellerJournalEntries\Pages;

use App\Filament\Seller\Resources\SellerJournalEntries\Actions\RecordEntryActions;
use App\Filament\Seller\Resources\SellerJournalEntries\SellerJournalEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListSellerJournalEntries extends ListRecords
{
    protected static string $resource = SellerJournalEntryResource::class;

    protected ?string $subheading = 'Every money movement in your business, in double-entry form. B2Bhaat activity is recorded for you.';

    protected function getHeaderActions(): array
    {
        return [
            RecordEntryActions::group(),
        ];
    }
}
