<?php

namespace App\Filament\Seller\Widgets;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\SubOrder;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class SellerRecentOrdersWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $sellerId = auth()->id();

        return $table
            ->query(
                SubOrder::query()
                    ->where('seller_id', $sellerId)
                    ->with(['order.customer', 'fulfillmentStatus'])
                    ->latest()
            )
            ->heading('Recent Store Packages & Dispatches')
            ->columns([
                TextColumn::make('sub_order_number')
                    ->label('Package #')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('order.customer.name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('fulfillmentStatus.name')
                    ->label('Fulfillment')
                    ->badge()
                    ->color(fn (SubOrder $record): array => match ($record->fulfillmentStatus?->slug) {
                        'delivered' => Color::Emerald,
                        'shipped', 'in_transit' => Color::Blue,
                        'processing' => Color::Indigo,
                        'unfulfilled' => Color::Gray,
                        'returned', 'cancelled' => Color::Red,
                        default => Color::Amber,
                    }),

                TextColumn::make('courier_name')
                    ->label('Courier')
                    ->placeholder('Unassigned'),

                TextColumn::make('tracking_number')
                    ->label('Consignment #')
                    ->fontFamily('mono')
                    ->placeholder('None'),

                TextColumn::make('net_earnings')
                    ->label('Net Payout')
                    ->formatStateUsing(fn ($state) => '৳'.number_format((float) $state, 2))
                    ->weight('bold')
                    ->color('success')
                    ->alignment('right'),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M d, Y H:i')
                    ->alignment('right'),
            ])
            ->recordActions([
                Action::make('manage')
                    ->label('View Package')
                    ->icon('heroicon-o-eye')
                    ->color('indigo')
                    ->url(fn (SubOrder $record): string => SubOrderResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
