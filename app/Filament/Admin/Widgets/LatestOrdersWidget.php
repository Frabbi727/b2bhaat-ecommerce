<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestOrdersWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Marketplace Orders (Live Feed)')
            ->description('Latest transactions routed through the multi-vendor escrow engine.')
            ->query(
                fn (): Builder => Order::with([
                    'customer',
                    'orderStatus',
                    'paymentStatus',
                    'paymentMethod',
                ])->latest()
            )
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order #')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('customer.name')
                    ->label('Buyer / Business')
                    ->searchable()
                    ->placeholder('Guest Customer'),

                TextColumn::make('orderStatus.name')
                    ->label('Order Status')
                    ->badge()
                    ->color(fn (Order $record) => match ($record->orderStatus?->slug) {
                        'completed' => Color::Green,
                        'confirmed' => Color::Blue,
                        'cancelled' => Color::Red,
                        default => Color::Amber,
                    }),

                TextColumn::make('paymentStatus.name')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (Order $record) => match ($record->paymentStatus?->slug) {
                        'paid' => Color::Emerald,
                        'refunded' => Color::Purple,
                        default => Color::Amber,
                    }),

                TextColumn::make('paymentMethod.name')
                    ->label('Channel')
                    ->badge()
                    ->color(Color::Slate),

                TextColumn::make('total_amount')
                    ->label('Grand Total')
                    ->state(fn (Order $record): float => (float) $record->total_amount)
                    ->formatStateUsing(fn ($state) => '৳'.number_format((float) $state, 2))
                    ->weight('bold')
                    ->alignment('right'),

                TextColumn::make('created_at')
                    ->label('Placed On')
                    ->dateTime('M d, Y H:i')
                    ->alignment('right'),
            ])
            ->recordActions([
                Action::make('view_invoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('indigo')
                    ->url(fn (Order $record): string => route('order.invoice', $record->order_number))
                    ->openUrlInNewTab(),
            ]);
    }
}
