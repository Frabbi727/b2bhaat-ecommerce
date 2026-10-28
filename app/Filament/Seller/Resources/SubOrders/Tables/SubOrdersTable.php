<?php

namespace App\Filament\Seller\Resources\SubOrders\Tables;

use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sub_order_number')
                    ->label('Package #')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('order.order_number')
                    ->label('Master Order')
                    ->fontFamily('mono')
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
                    ->placeholder('Unassigned')
                    ->searchable(),
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->fontFamily('mono')
                    ->placeholder('None')
                    ->searchable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('BDT')
                    ->sortable(),
                TextColumn::make('commission_amount')
                    ->label('Platform Fee')
                    ->money('BDT')
                    ->sortable(),
                TextColumn::make('net_earnings')
                    ->label('Net Payout')
                    ->money('BDT')
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('created_at')
                    ->label('Received Date')
                    ->dateTime('M d, Y h:i A')
                    ->sortable(),
            ])
            ->recordActions([
                // 1. Dispatch Courier Action
                Action::make('dispatchCourier')
                    ->label('Dispatch Courier')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->visible(fn (SubOrder $record) => in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
                    ->schema([
                        Select::make('courier_name')
                            ->label('Select Courier Service')
                            ->options([
                                'Steadfast Courier' => 'Steadfast Courier',
                                'Pathao Courier' => 'Pathao Courier',
                                'RedX Delivery' => 'RedX Delivery',
                                'Paperfly' => 'Paperfly',
                                'Sundarban Courier' => 'Sundarban Courier',
                            ])
                            ->required(),
                        TextInput::make('tracking_number')
                            ->label('Consignment / Tracking Number')
                            ->placeholder('e.g. ST-992381')
                            ->required(),
                        TextInput::make('hub_location')
                            ->label('Dispatch Warehouse / Hub')
                            ->default('Vendor Warehouse')
                            ->required(),
                        Textarea::make('note')
                            ->label('Consignment Notes')
                            ->default('Parcel handed over to courier hub for last-mile transit.'),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        $shippedStatus = FulfillmentStatus::where('slug', 'shipped')->firstOrFail();

                        $record->update([
                            'courier_name' => $data['courier_name'],
                            'tracking_number' => $data['tracking_number'],
                            'fulfillment_status_id' => $shippedStatus->id,
                        ]);

                        OrderTrackingLog::create([
                            'sub_order_id' => $record->id,
                            'fulfillment_status_id' => $shippedStatus->id,
                            'title' => 'Handed Over to Courier',
                            'description' => $data['note'],
                            'hub_location' => $data['hub_location'],
                            'logged_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Consignment Dispatched')
                            ->body("Sub-Order #{$record->sub_order_number} assigned to {$data['courier_name']}.")
                            ->success()
                            ->send();
                    }),

                // 2. Mark Delivered Action (triggers 48-hr escrow countdown)
                Action::make('markDelivered')
                    ->label('Mark Delivered')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SubOrder $record) => in_array($record->fulfillmentStatus?->slug, ['shipped', 'in_transit', 'out_for_delivery']))
                    ->requiresConfirmation()
                    ->modalHeading('Confirm Delivery Handover')
                    ->modalDescription('Has the customer received and accepted this consignment? Marking as delivered initiates the 48-hour escrow dispute window, after which funds will automatically transfer to your available balance.')
                    ->action(function (SubOrder $record): void {
                        $deliveredStatus = FulfillmentStatus::where('slug', 'delivered')->firstOrFail();

                        $record->update([
                            'fulfillment_status_id' => $deliveredStatus->id,
                        ]);

                        OrderTrackingLog::create([
                            'sub_order_id' => $record->id,
                            'fulfillment_status_id' => $deliveredStatus->id,
                            'title' => 'Consignment Delivered',
                            'description' => 'Parcel confirmed delivered by courier service. 48-hour escrow dispute grace period activated.',
                            'hub_location' => 'Customer Destination',
                            'logged_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Delivery Confirmed')
                            ->body("Sub-Order #{$record->sub_order_number} marked delivered. Escrow funds will automatically release in 48 hours.")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
