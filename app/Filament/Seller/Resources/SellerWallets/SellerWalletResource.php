<?php

namespace App\Filament\Seller\Resources\SellerWallets;

use App\Filament\Seller\Resources\SellerWallets\Pages\ListSellerWallets;
use App\Filament\Seller\Resources\SellerWallets\Schemas\SellerWalletForm;
use App\Filament\Seller\Resources\SellerWallets\Tables\SellerWalletsTable;
use App\Models\SellerWallet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SellerWalletResource extends Resource
{
    protected static ?string $model = SellerWallet::class;

    protected static ?string $navigationLabel = 'Wallet & payouts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('seller_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return SellerWalletForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SellerWalletsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerWallets::route('/'),
        ];
    }
}
