<?php

namespace App\Filament\Admin\Resources\Banners\Tables;

use App\Models\Banner;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderBy('position')->orderBy('sort_order'))
            ->columns([
                ImageColumn::make('desktop_image_url')
                    ->label('')
                    ->disk('public')
                    ->height(44)
                    ->width(96)
                    ->extraImgAttributes(['class' => 'rounded-md object-cover']),

                TextColumn::make('title')
                    ->label('Banner')
                    ->weight('medium')
                    ->description(fn (Banner $record): ?string => $record->subtitle)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('position')
                    ->label('Where')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => Banner::POSITIONS[$state] ?? $state),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('live_status')
                    ->label('Right now')
                    ->badge()
                    ->state(fn (Banner $record): string => $record->liveStatusLabel())
                    ->color(fn (Banner $record): string => $record->isAvailableNow() ? 'success' : ($record->is_active && $record->start_at?->isFuture() ? 'info' : 'gray')),

                ToggleColumn::make('is_active')
                    ->label('On'),
            ])
            ->filters([
                SelectFilter::make('position')
                    ->label('Where')
                    ->options(Banner::POSITIONS),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->emptyStateHeading('No banners yet')
            ->emptyStateDescription('Add one for the home page slider, the offer cards or the announcement bar.');
    }
}
