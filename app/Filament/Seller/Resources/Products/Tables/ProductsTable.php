<?php

namespace App\Filament\Seller\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Product Title')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color(Color::Slate)
                    ->searchable(),

                TextColumn::make('salesChannel.name')
                    ->label('Channel')
                    ->badge()
                    ->color(fn (Product $record) => match ($record->salesChannel?->slug) {
                        'both' => Color::Indigo,
                        'wholesale' => Color::Emerald,
                        default => Color::Amber,
                    }),

                TextColumn::make('retailPrice.unit_price')
                    ->label('Retail Rate')
                    ->money('BDT')
                    ->placeholder('N/A')
                    ->sortable(),

                TextColumn::make('wholesalePrice.unit_price')
                    ->label('Wholesale Rate (MOQ)')
                    ->formatStateUsing(function ($state, Product $record) {
                        if (! $state) {
                            return 'N/A';
                        }
                        $moq = $record->wholesalePrice?->min_quantity ?? 1;

                        return '৳'.number_format($state, 2)." (MOQ: {$moq})";
                    })
                    ->badge()
                    ->color(Color::Emerald),

                TextColumn::make('variations_count')
                    ->counts('variations')
                    ->label('Variations')
                    ->sortable(),

                TextColumn::make('variations_sum_stock_quantity')
                    ->sum('variations', 'stock_quantity')
                    ->label('Total Stock')
                    ->badge()
                    ->color(fn ($state) => $state > 50 ? Color::Green : Color::Amber)
                    ->suffix(fn (Product $record) => ' '.($record->unit?->symbol ?? 'pcs')),

                // 1-Click Instant Active/Deactivate Toggle
                ToggleColumn::make('is_active')
                    ->label('Active on Store')
                    ->onColor('success')
                    ->offColor('danger'),

                TextColumn::make('created_at')
                    ->label('Added On')
                    ->date('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
