<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts;

use App\Filament\Admin\Resources\AccountingAccounts\Pages\CreateAccountingAccount;
use App\Filament\Admin\Resources\AccountingAccounts\Pages\EditAccountingAccount;
use App\Filament\Admin\Resources\AccountingAccounts\Pages\ListAccountingAccounts;
use App\Filament\Admin\Resources\AccountingAccounts\Schemas\AccountingAccountForm;
use App\Filament\Admin\Resources\AccountingAccounts\Tables\AccountingAccountsTable;
use App\Models\Accounting\AccountingAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AccountingAccountResource extends Resource
{
    protected static ?string $model = AccountingAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static ?string $modelLabel = 'Account';

    protected static ?string $pluralModelLabel = 'Chart of Accounts';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->role?->slug && in_array($user->role->slug, ['super_admin', 'admin'], true));
    }

    public static function form(Schema $schema): Schema
    {
        return AccountingAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingAccountsTable::configure($table);
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
            'index' => ListAccountingAccounts::route('/'),
            'create' => CreateAccountingAccount::route('/create'),
            'edit' => EditAccountingAccount::route('/{record}/edit'),
        ];
    }
}
