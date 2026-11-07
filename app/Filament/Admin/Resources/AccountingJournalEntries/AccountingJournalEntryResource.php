<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries;

use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ListAccountingJournalEntries;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ViewAccountingJournalEntry;
use App\Filament\Admin\Resources\AccountingJournalEntries\Schemas\AccountingJournalEntryInfolist;
use App\Filament\Admin\Resources\AccountingJournalEntries\Tables\AccountingJournalEntriesTable;
use App\Models\Accounting\AccountingJournalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AccountingJournalEntryResource extends Resource
{
    protected static ?string $model = AccountingJournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Journal Entries';

    protected static ?string $modelLabel = 'Journal Entry';

    protected static ?string $pluralModelLabel = 'Journal Entries';

    protected static ?string $recordTitleAttribute = 'journal_number';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->role?->slug && in_array($user->role->slug, ['super_admin', 'admin'], true));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountingJournalEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingJournalEntriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingJournalEntries::route('/'),
            'view' => ViewAccountingJournalEntry::route('/{record}'),
        ];
    }
}
