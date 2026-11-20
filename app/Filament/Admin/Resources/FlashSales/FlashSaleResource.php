<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales;

use App\Filament\Admin\Resources\FlashSales\Pages\CreateFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\Pages\ListFlashSales;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\ProductsRelationManager;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\SubmissionsRelationManager;
use App\Filament\Admin\Resources\FlashSales\Schemas\FlashSaleForm;
use App\Filament\Admin\Resources\FlashSales\Tables\FlashSalesTable;
use App\Filament\Admin\Resources\FlashSales\Widgets\FlashSaleStatsOverview;
use App\Models\FlashSale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FlashSaleResource extends Resource
{
    protected static ?string $model = FlashSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'flash sale';

    protected static ?string $pluralModelLabel = 'flash sales';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $count = FlashSale::query()->live()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return FlashSaleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlashSalesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SubmissionsRelationManager::class,
            ProductsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            FlashSaleStatsOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlashSales::route('/'),
            'create' => CreateFlashSale::route('/create'),
            'edit' => EditFlashSale::route('/{record}/edit'),
        ];
    }
}
