<?php

namespace App\Filament\Seller\Resources\SubOrders;

use App\Filament\Seller\Resources\SubOrders\Pages\CreateSubOrder;
use App\Filament\Seller\Resources\SubOrders\Pages\EditSubOrder;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderForm;
use App\Filament\Seller\Resources\SubOrders\Tables\SubOrdersTable;
use App\Models\SubOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubOrderResource extends Resource
{
    protected static ?string $model = SubOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SubOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('seller_id', auth()->id());
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
            'index' => ListSubOrders::route('/'),
            'create' => CreateSubOrder::route('/create'),
            'edit' => EditSubOrder::route('/{record}/edit'),
        ];
    }
}
