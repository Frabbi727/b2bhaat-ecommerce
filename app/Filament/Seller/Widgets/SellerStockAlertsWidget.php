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
            ->heading('Critical Low-Stock Alerts')
            ->description("SKUs with inventory at or below threshold ({$threshold} units). Restock to prevent missed wholesale & retail sales.")
            ->query(
                fn (): Builder => ProductVariation::query()
                    ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
                    ->with(['product', 'attributeValues.attribute'])
                    ->where('stock_quantity', '<=', $threshold)
                    ->orderBy('stock_quantity', 'asc')
            )
            ->columns([
                TextColumn::make('product.title')
                    ->label('Product Title')
                    ->weight('bold')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('sku')
                    ->label('SKU Code')
                    ->fontFamily('mono')
                    ->badge()
                    ->color(Color::Slate),

                TextColumn::make('variation_attributes')
                    ->label('Variation / Spec')
                    ->state(fn (ProductVariation $record): string => $record->attributeValues->map(fn ($av) => "{$av->attribute?->name}: {$av->value}")->implode(', ') ?: 'Standard Base Item'),

                TextColumn::make('stock_quantity')
                    ->label('Remaining Stock')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->badge()
                    ->color(fn (ProductVariation $record): array => $record->stock_quantity == 0 ? Color::Red : Color::Amber),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Edit Product')
                    ->icon('heroicon-m-pencil-square')
                    ->url(fn (ProductVariation $record): string => ProductResource::getUrl('edit', ['record' => $record->product_id])),
            ]);
    }
}
