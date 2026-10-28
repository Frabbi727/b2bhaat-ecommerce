<?php

namespace App\Filament\Seller\Resources\RfqRequests;

use App\Filament\Seller\Resources\RfqRequests\Pages\CreateRfqRequest;
use App\Filament\Seller\Resources\RfqRequests\Pages\EditRfqRequest;
use App\Filament\Seller\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Filament\Seller\Resources\RfqRequests\Schemas\RfqRequestForm;
use App\Filament\Seller\Resources\RfqRequests\Tables\RfqRequestsTable;
use App\Models\RfqRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RfqRequestResource extends Resource
{
    protected static ?string $model = RfqRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Wholesale RFQs';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('seller_id', auth()->id());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('seller_id', auth()->id())->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return RfqRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RfqRequestsTable::configure($table);
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
            'index' => ListRfqRequests::route('/'),
            'create' => CreateRfqRequest::route('/create'),
            'edit' => EditRfqRequest::route('/{record}/edit'),
        ];
    }
}
