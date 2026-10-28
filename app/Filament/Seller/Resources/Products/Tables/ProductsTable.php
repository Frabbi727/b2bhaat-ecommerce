<?php

namespace App\Filament\Seller\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->color(fn ($state) => match (true) {
                        $state <= 0 => Color::Red,
                        $state <= 10 => Color::Amber,
                        $state <= 50 => Color::Yellow,
                        default => Color::Green,
                    })
                    ->formatStateUsing(fn ($state) => match (true) {
                        $state <= 0 => '0 (Out of Stock)',
                        $state <= 10 => "{$state} (Low Stock)",
                        default => (string) $state,
                    })
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
                SelectFilter::make('stock_status')
                    ->label('Stock Status')
                    ->options([
                        'out_of_stock' => 'Out of Stock (0)',
                        'low_stock' => 'Low Stock (≤ 10)',
                        'in_stock' => 'In Stock (> 10)',
                    ])
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === 'out_of_stock') {
                            $query->whereDoesntHave('variations', fn ($q) => $q->where('stock_quantity', '>', 0));
                        } elseif (($data['value'] ?? null) === 'low_stock') {
                            $query->whereHas('variations', fn ($q) => $q->where('stock_quantity', '<=', 10));
                        } elseif (($data['value'] ?? null) === 'in_stock') {
                            $query->whereHas('variations', fn ($q) => $q->where('stock_quantity', '>', 10));
                        }
                    }),
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
