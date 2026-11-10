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
    protected static ?int $sort = 3;

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
                    ->limit(6)
            )
            ->heading('Latest orders')
            ->paginated(false)
            ->searchable(false)
            ->headerActions([
                Action::make('all')
                    ->label('View all orders')
                    ->link()
                    ->url(SubOrderResource::getUrl('index')),
            ])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('New orders for your products will show up here.')
            ->columns([
                TextColumn::make('sub_order_number')
                    ->label('Package #')
                    ->fontFamily('mono')
                    ->weight('medium'),

                TextColumn::make('order.customer.name')
                    ->visibleFrom('md')
                    ->label('Customer')
                    ->placeholder('Guest'),

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
                    ->visibleFrom('md')
                    ->label('Courier')
                    ->placeholder('Not booked')
                    ->color('gray'),

                TextColumn::make('net_earnings')
                    ->label('Your payout')
                    ->formatStateUsing(fn ($state) => '৳'.number_format((float) $state, 2))
                    ->weight('medium')
                    ->color(fn (SubOrder $record): string => $record->fulfillmentStatus?->slug === 'cancelled' ? 'gray' : 'success')
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Received')
                    ->since()
                    ->dateTimeTooltip('d M Y, g:i A')
                    ->alignEnd(),
            ])
            ->recordUrl(fn (SubOrder $record): string => SubOrderResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('manage')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-right')
                    ->color('gray')
                    ->url(fn (SubOrder $record): string => SubOrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
