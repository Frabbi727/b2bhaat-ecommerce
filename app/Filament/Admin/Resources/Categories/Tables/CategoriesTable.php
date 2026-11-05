<?php

namespace App\Filament\Admin\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')->withCount(['products', 'children']))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Category $record): ?string => $record->parent ? 'in '.$record->parent->name : 'Top level')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('children_count')
                    ->label('Subcategories')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Visible'),
                TextColumn::make('slug')
                    ->label('URL handle')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('parent_id')
                    ->label('Level')
                    ->nullable()
                    ->trueLabel('Subcategories')
                    ->falseLabel('Top level'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::deleteAction(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('Sellers need categories before they can list products.');
    }

    /**
     * Products and subcategories need their category; only empty ones can be deleted.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (Category $record): bool => $record->products()->exists() || $record->children()->exists());
    }
}
