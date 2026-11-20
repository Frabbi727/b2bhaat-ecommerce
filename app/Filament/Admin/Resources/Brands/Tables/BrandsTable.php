<?php

namespace App\Filament\Admin\Resources\Brands\Tables;

use App\Models\Brand;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('slug')
                    ->label('URL handle')
                    ->searchable()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Available'),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->emptyStateHeading('No brands yet');
    }

    /**
     * Only unused brands can be deleted: products must keep the brand they were listed with.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (Brand $record): bool => $record->products()->exists());
    }
}
