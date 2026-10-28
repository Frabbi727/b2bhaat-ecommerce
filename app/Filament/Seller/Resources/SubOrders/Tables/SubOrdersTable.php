<?php

namespace App\Filament\Seller\Resources\SubOrders\Tables;

use App\Models\FulfillmentStatus;
use App\Models\OrderTrackingLog;
use App\Models\SubOrder;
use App\Services\Logistics\CourierBookingService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Logistics\SelfDeliveryService;
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
                    ->label('Courier / Fleet')
                    ->placeholder('Unassigned')
                    ->searchable(),
                TextColumn::make('is_self_delivered')
                    ->label('Delivery Mode')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Factory Direct Fleet' : 'Courier')
                    ->color(fn (bool $state) => $state ? Color::Indigo : Color::Slate),
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

                // 2. Mark Delivered Action (triggers settlement and parent reconciliation)
                Action::make('markDelivered')
                    ->label('Mark Delivered')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SubOrder $record) => in_array($record->fulfillmentStatus?->slug, ['shipped', 'in_transit', 'out_for_delivery']))
                    ->requiresConfirmation()
                    ->modalHeading('Confirm Delivery Handover')
                    ->modalDescription('Has the customer received and accepted this consignment? Marking as delivered confirms COD cash collection and settles net earnings into your seller wallet balance.')
                    ->action(function (SubOrder $record): void {
                        app(CourierStatusService::class)->updateStatus(
                            subOrder: $record,
                            statusSlug: 'delivered',
                            hubLocation: 'Customer Destination',
                            title: 'Consignment Delivered',
                            description: 'Parcel confirmed delivered by courier. Cash on delivery collected and net earnings settled to seller wallet.'
                        );

                        Notification::make()
                            ->title('Delivery Confirmed & Settled')
                            ->body("Sub-Order #{$record->sub_order_number} marked delivered. Net earnings (৳{$record->net_earnings}) credited to your wallet.")
                            ->success()
                            ->send();
                    }),

                // 3. Courier Status Simulator (Testing tool for status progression)
                Action::make('simulateCourier')
                    ->label('Simulate Courier')
                    ->icon('heroicon-o-bolt')
                    ->color('info')
                    ->visible(fn (SubOrder $record) => in_array($record->fulfillmentStatus?->slug, ['shipped', 'in_transit', 'out_for_delivery']))
                    ->schema([
                        Select::make('status')
                            ->label('New Courier Progression State')
                            ->options([
                                'in_transit' => '🚚 In Transit (Sorting Hub)',
                                'out_for_delivery' => '🛵 Out for Delivery (Rider Dispatched)',
                                'delivered' => '✅ Delivered & Cash Collected (Settles Escrow)',
                                'returned' => '↩️ Returned / Customer Refused (RTO)',
                            ])
                            ->default(fn (SubOrder $record) => match ($record->fulfillmentStatus?->slug) {
                                'shipped' => 'in_transit',
                                'in_transit' => 'out_for_delivery',
                                default => 'delivered',
                            })
                            ->required(),
                        TextInput::make('hub_location')
                            ->label('Courier Hub / Location')
                            ->default('Tejgaon Sorting Hub, Dhaka')
                            ->required(),
                        TextInput::make('title')
                            ->label('Log Headline')
                            ->placeholder('e.g. Scanned at Sorting Facility'),
                        Textarea::make('description')
                            ->label('Detailed Tracking Event Note')
                            ->default('Automated status transition from courier tracking webhook.'),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        app(CourierStatusService::class)->updateStatus(
                            subOrder: $record,
                            statusSlug: $data['status'],
                            hubLocation: $data['hub_location'] ?? null,
                            title: $data['title'] ?? null,
                            description: $data['description'] ?? null
                        );

                        $statusName = ucfirst(str_replace('_', ' ', $data['status']));
                        $message = $data['status'] === 'delivered'
                            ? "Sub-Order #{$record->sub_order_number} delivered! Net earnings (৳{$record->net_earnings}) settled to wallet."
                            : "Sub-Order #{$record->sub_order_number} updated to {$statusName}.";

                        Notification::make()
                            ->title('Courier Status Updated')
                            ->body($message)
                            ->success()
                            ->send();
                    }),

                // 4. Print 4x6 Thermal Shipping Label
                Action::make('printShippingLabel')
                    ->label('Shipping Label')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (SubOrder $record) => route('seller.sub_order.label', $record->sub_order_number))
                    ->openUrlInNewTab(),

                // 5. Print Commercial Delivery Challan (A4)
                Action::make('printDeliveryChallan')
                    ->label('Delivery Challan')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->url(fn (SubOrder $record) => route('seller.sub_order.challan', $record->sub_order_number))
                    ->openUrlInNewTab(),

                // 6. Factory Direct Fleet Dispatch (Decision D5)
                Action::make('dispatchFactoryFleet')
                    ->label('Dispatch Factory Fleet')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->visible(fn (SubOrder $record) => ! $record->is_self_delivered && in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
                    ->schema([
                        TextInput::make('hub_location')
                            ->label('Factory Dispatch Bay / Hub Location')
                            ->default(fn (SubOrder $record) => ($record->seller?->sellerProfile?->store_name ?? 'Factory').' Factory Bay, Dhaka')
                            ->required(),
                        TextInput::make('driver_contact')
                            ->label('Fleet Driver / Representative Phone')
                            ->placeholder('e.g. +8801700000000')
                            ->required(),
                        Textarea::make('dispatch_note')
                            ->label('Dispatch & Delivery Instructions')
                            ->default('Direct truck fleet delivery. Customer OTP handover code required at destination.')
                            ->required(),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        $code = app(SelfDeliveryService::class)->dispatchSelfDelivery(
                            subOrder: $record,
                            hubLocation: $data['hub_location'],
                            driverContact: $data['driver_contact'],
                            dispatchNote: $data['dispatch_note']
                        );

                        Notification::make()
                            ->title('Dispatched via Factory Direct Fleet!')
                            ->body("Handover verification code {$code} generated for the recipient.")
                            ->success()
                            ->send();
                    }),

                // 7. Verify Customer Handover Code (Decision D5)
                Action::make('verifyHandoverCode')
                    ->label('Confirm Handover Code')
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->visible(fn (SubOrder $record) => $record->is_self_delivered && $record->fulfillmentStatus?->slug !== 'delivered')
                    ->schema([
                        TextInput::make('handover_code')
                            ->label('6-Digit Customer Handover Code')
                            ->placeholder('e.g. 849201')
                            ->required()
                            ->length(6),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        try {
                            app(SelfDeliveryService::class)->verifyAndConfirmHandover(
                                subOrder: $record,
                                inputCode: $data['handover_code']
                            );

                            Notification::make()
                                ->title('Handover Confirmed & Package Delivered!')
                                ->body("Handover code verified successfully. Sub-Order #{$record->sub_order_number} marked as Delivered and ৳{$record->net_earnings} settled to wallet.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Verification Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // 6. Automated Courier Pickup Booking
                Action::make('bookCourierPickup')
                    ->label('Book Courier Pickup')
                    ->icon('heroicon-o-calendar-days')
                    ->color('indigo')
                    ->visible(fn (SubOrder $record) => in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
                    ->schema([
                        Select::make('courier_name')
                            ->label('Courier Provider')
                            ->options([
                                'Steadfast Courier' => 'Steadfast Courier (Automated Consignment API)',
                                'Pathao Courier' => 'Pathao Courier (Express Last-Mile)',
                                'RedX Delivery' => 'RedX Delivery (Nationwide Hubs)',
                                'Paperfly' => 'Paperfly (Doorstep Delivery)',
                                'Sundarban Courier' => 'Sundarban Courier (District Branch)',
                            ])
                            ->default('Steadfast Courier')
                            ->required(),

                        Select::make('pickup_slot')
                            ->label('Requested Pickup Timeslot')
                            ->options([
                                'Today Express (2:00 PM - 6:00 PM)' => 'Today Express (2:00 PM - 6:00 PM)',
                                'Tomorrow Morning (10:00 AM - 1:00 PM)' => 'Tomorrow Morning (10:00 AM - 1:00 PM)',
                                'Tomorrow Afternoon (2:00 PM - 6:00 PM)' => 'Tomorrow Afternoon (2:00 PM - 6:00 PM)',
                            ])
                            ->default('Today Express (2:00 PM - 6:00 PM)')
                            ->required(),

                        TextInput::make('pickup_address')
                            ->label('Pickup Warehouse / Factory Location')
                            ->default(fn (SubOrder $record) => ($record->seller?->sellerProfile?->store_name ?? 'Factory').' Main Warehouse, Dhaka')
                            ->required(),

                        TextInput::make('estimated_weight')
                            ->label('Total Weight / Cartons')
                            ->default('1.5 kg (1 Carton)')
                            ->required(),

                        TextInput::make('instructions')
                            ->label('Special Handling Instructions')
                            ->default('Wholesale goods consignment, handle with care.')
                            ->required(),
                    ])
                    ->action(function (SubOrder $record, array $data): void {
                        $updated = app(CourierBookingService::class)->schedulePickup($record, $data);

                        Notification::make()
                            ->title('Courier Pickup Scheduled!')
                            ->body("Consignment tracking #{$updated->tracking_number} assigned via {$data['courier_name']}.")
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
