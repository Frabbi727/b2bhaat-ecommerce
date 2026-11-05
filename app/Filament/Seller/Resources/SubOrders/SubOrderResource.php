<?php

namespace App\Filament\Seller\Resources\SubOrders;

use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Filament\Seller\Resources\SubOrders\Pages\ViewSubOrder;
use App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderForm;
use App\Filament\Seller\Resources\SubOrders\Tables\SubOrdersTable;
use App\Models\SubOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SubOrderResource extends Resource
{
    protected static ?string $model = SubOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'order';

    protected static ?string $pluralModelLabel = 'orders';

    protected static ?string $recordTitleAttribute = 'sub_order_number';

    public static function getNavigationBadge(): ?string
    {
        $awaitingDispatch = static::getModel()::where('seller_id', auth()->id())
            ->whereHas('fulfillmentStatus', fn (Builder $q) => $q->whereIn('slug', ['unfulfilled', 'processing']))
            ->count();

        return $awaitingDispatch > 0 ? (string) $awaitingDispatch : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Orders waiting to be packed and dispatched';
    }

    protected static ?string $navigationLabel = 'Orders';

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
            'view' => ViewSubOrder::route('/{record}'),
        ];
    }
}
