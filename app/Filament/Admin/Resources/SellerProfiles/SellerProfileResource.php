<?php

namespace App\Filament\Admin\Resources\SellerProfiles;

use App\Filament\Admin\Resources\SellerProfiles\Pages\CreateSellerProfile;
use App\Filament\Admin\Resources\SellerProfiles\Pages\EditSellerProfile;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Admin\Resources\SellerProfiles\Schemas\SellerProfileForm;
use App\Filament\Admin\Resources\SellerProfiles\Tables\SellerProfilesTable;
use App\Models\SellerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SellerProfileResource extends Resource
{
    protected static ?string $model = SellerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static UnitEnum|string|null $navigationGroup = 'Sellers & Customers';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Sellers';

    protected static ?string $modelLabel = 'seller';

    protected static ?string $pluralModelLabel = 'sellers';

    public static function getNavigationBadge(): ?string
    {
        $count = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return SellerProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SellerProfilesTable::configure($table);
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
            'index' => ListSellerProfiles::route('/'),
            'create' => CreateSellerProfile::route('/create'),
            'edit' => EditSellerProfile::route('/{record}/edit'),
        ];
    }
}
