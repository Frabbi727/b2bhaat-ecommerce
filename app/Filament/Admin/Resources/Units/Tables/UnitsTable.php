<?php

namespace App\Filament\Admin\Resources\Units\Tables;

use App\Models\Unit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitsTable
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
                TextColumn::make('symbol')
                    ->label('Short code')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
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
            ->emptyStateHeading('No units yet');
    }

    /**
     * Only unused units can be deleted: products must keep the unit they were listed with.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (Unit $record): bool => $record->products()->exists());
    }
}
