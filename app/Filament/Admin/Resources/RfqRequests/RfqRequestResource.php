<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RfqRequests;

use App\Filament\Admin\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Models\RfqRequest;
use App\Services\Formatting\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only view of bulk quote requests, so the admin can see which buyers are waiting on which seller.
 */
class RfqRequestResource extends Resource
{
    protected static ?string $model = RfqRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Quote requests';

    protected static ?string $modelLabel = 'quote request';

    protected static ?string $pluralModelLabel = 'quote requests';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'seller.sellerProfile']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.title')->label('Product')->searchable()->limit(40),
                TextColumn::make('seller.sellerProfile.store_name')->label('Seller'),
                TextColumn::make('buyer_name')->label('Buyer')->searchable()
                    ->description(fn (RfqRequest $record): ?string => $record->company_name),
                TextColumn::make('buyer_phone')->label('Mobile')->copyable(),
                TextColumn::make('target_quantity')->label('Quantity')->numeric()->sortable(),
                TextColumn::make('target_unit_price')->label('Buyer\'s price')->formatStateUsing(fn ($state): string => Money::taka($state))->placeholder('—'),
                TextColumn::make('seller_quoted_price')->label('Seller\'s quote')->formatStateUsing(fn ($state): string => Money::taka($state))->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Asked')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(fn (): array => RfqRequest::query()->distinct()->pluck('status', 'status')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRfqRequests::route('/'),
        ];
    }
}
