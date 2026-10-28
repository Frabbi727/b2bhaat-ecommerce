<?php

namespace App\Filament\Admin\Resources\WalletTransactions;

use App\Filament\Admin\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Admin\Resources\WalletTransactions\Schemas\WalletTransactionForm;
use App\Filament\Admin\Resources\WalletTransactions\Tables\WalletTransactionsTable;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationLabel = 'Seller Payout Disbursements';

    protected static ?string $modelLabel = 'Payout & Ledger Transaction';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function form(Schema $schema): Schema
    {
        return WalletTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WalletTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
        ];
    }
}
