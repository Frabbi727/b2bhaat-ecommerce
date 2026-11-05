<?php

namespace App\Filament\Seller\Resources\ReturnRequests;

use App\Filament\Seller\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Filament\Seller\Resources\ReturnRequests\Schemas\ReturnRequestForm;
use App\Filament\Seller\Resources\ReturnRequests\Tables\ReturnRequestsTable;
use App\Models\ReturnRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReturnRequestResource extends Resource
{
    protected static ?string $model = ReturnRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Returns & disputes';

    protected static ?string $modelLabel = 'Dispute / Return';

    protected static ?string $pluralModelLabel = 'Returns & Disputes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('subOrder', fn (Builder $q) => $q->where('seller_id', auth()->id()));
    }

    public static function getNavigationBadge(): ?string
    {
        $pendingCount = static::getModel()::whereHas('subOrder', fn (Builder $q) => $q->where('seller_id', auth()->id()))
            ->where('status', ReturnRequest::STATUS_PENDING)
            ->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    public static function form(Schema $schema): Schema
    {
        return ReturnRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReturnRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnRequests::route('/'),
        ];
    }
}
