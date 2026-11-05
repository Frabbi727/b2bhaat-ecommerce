<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\ProductVariation;
use App\Services\Settings\StoreConfigurationService;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SellerStockAlertsWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $sellerId = auth()->id();
        $threshold = app(StoreConfigurationService::class)->getLowStockThreshold();

        return $table
            ->heading('Running low on stock')
            ->description("Variations with {$threshold} units or fewer. Restock them before buyers see “out of stock”.")
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Stock levels look healthy')
            ->emptyStateDescription('Nothing is at or below your low-stock threshold.')
            ->query(
                fn (): Builder => ProductVariation::query()
                    ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId)->where('is_active', true))
                    ->where('is_active', true)
                    ->with(['product', 'attributeValues.attribute'])
                    ->where('stock_quantity', '<=', $threshold)
                    ->orderBy('stock_quantity', 'asc')
            )
            ->columns([
                TextColumn::make('product.title')
                    ->label('Product')
                    ->weight('medium')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->color('gray'),

                TextColumn::make('variation_attributes')
                    ->label('Options')
                    ->state(fn (ProductVariation $record): string => $record->attributeValues->map(fn ($av) => "{$av->attribute?->name}: {$av->value}")->implode(', ') ?: 'Single item'),

                TextColumn::make('stock_quantity')
                    ->label('In stock')
                    ->alignEnd()
                    ->weight('medium')
                    ->badge()
                    ->color(fn (ProductVariation $record): array => $record->stock_quantity == 0 ? Color::Red : Color::Amber),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Restock')
                    ->icon('heroicon-m-pencil-square')
                    ->url(fn (ProductVariation $record): string => ProductResource::getUrl('edit', ['record' => $record->product_id])),
            ]);
    }
}
