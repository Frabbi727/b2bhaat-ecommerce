<?php

namespace App\Filament\Seller\Resources\SubOrders\Tables;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FulfillmentStatus;
use App\Models\SubOrder;
use App\Services\Logistics\CourierBookingService;
use App\Services\Logistics\CourierStatusService;
use App\Services\Logistics\SelfDeliveryService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->label('Customer order')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order.customer.name')
                    ->label('Customer')
                    ->placeholder('Guest')
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
                    ->placeholder('Not booked')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('is_self_delivered')
                    ->label('Delivery')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Own fleet' : 'Courier')
                    ->color(fn (bool $state) => $state ? Color::Indigo : Color::Zinc)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_number')
                    ->label('Tracking #')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state, 2))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_amount')
                    ->label('Platform fee')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state, 2))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_earnings')
                    ->label('Your payout')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state, 2))
                    ->alignEnd()
                    ->weight('medium')
                    ->color(fn (SubOrder $record): string => $record->fulfillmentStatus?->slug === 'cancelled' ? 'gray' : 'success')
                    ->description(fn (SubOrder $record): ?string => $record->fulfillmentStatus?->slug === 'cancelled' ? 'Not paid out' : null),
                TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->dateTimeTooltip('M d, Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.customer', 'fulfillmentStatus']))
            ->searchPlaceholder('Search package, customer or tracking #')
            ->filters([
                SelectFilter::make('fulfillment_status_id')
                    ->label('Status')
                    ->multiple()
                    ->options(fn () => FulfillmentStatus::pluck('name', 'id')),
                TernaryFilter::make('is_self_delivered')
                    ->label('Delivery')
                    ->trueLabel('Own fleet')
                    ->falseLabel('Courier')
                    ->placeholder('All'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Received from'),
                        DatePicker::make('until')->label('Received until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders for your products appear here as soon as a buyer checks out.')
            ->recordActions([
                ViewAction::make()->label('Open'),
                ActionGroup::make([
                    // 1. Dispatch Courier Action
                    Action::make('dispatchCourier')
                        ->label('Dispatch Courier')
                        ->icon('heroicon-o-truck')
                        ->color('primary')
                        ->visible(fn (SubOrder $record) => $record->isClearedForDispatch() && in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
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
                                ->label('Tracking number')
                                ->placeholder('e.g. ST-992381')
                                ->required(),
                            TextInput::make('hub_location')
                                ->label('Dispatch Warehouse / Hub')
                                ->default('Vendor Warehouse')
                                ->required(),
                            Textarea::make('note')
                                ->label('Notes for the courier')
                                ->default('Parcel handed over to courier hub for last-mile transit.'),
                        ])
                        ->action(function (SubOrder $record, array $data): void {
                            try {
                                app(CourierStatusService::class)->handOver(
                                    $record,
                                    'shipped',
                                    ['courier_name' => $data['courier_name'], 'tracking_number' => $data['tracking_number']],
                                    'Handed Over to Courier',
                                    (string) ($data['note'] ?? ''),
                                    (string) $data['hub_location'],
                                );
                            } catch (InvalidStatusTransitionException|DomainException $exception) {
                                Notification::make()->title('Not dispatched')->body($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()
                                ->title('Parcel handed to the courier')
                                ->body("Order #{$record->sub_order_number} assigned to {$data['courier_name']}.")
                                ->success()
                                ->send();
                        }),

                    // Courier deliveries are confirmed only by the signed courier webhook (D4);
                    // self-deliveries only by the buyer's handover code (D5). Sellers cannot mark delivery.

                    // 2. Courier Status Simulator (local/testing only)
                    Action::make('simulateCourier')
                        ->label('Simulate Courier')
                        ->icon('heroicon-o-bolt')
                        ->color('info')
                        ->visible(fn (SubOrder $record) => app()->environment('local', 'testing')
                            && in_array($record->fulfillmentStatus?->slug, ['shipped', 'in_transit', 'out_for_delivery']))
                        ->schema([
                            Select::make('status')
                                ->label('New Courier Progression State')
                                ->options([
                                    'in_transit' => '🚚 In Transit (Sorting Hub)',
                                    'out_for_delivery' => '🛵 Out for Delivery (Rider Dispatched)',
                                    'delivered' => '✅ Delivered (money on hold for 48 hours)',
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
                                ? "Order #{$record->sub_order_number} delivered. ৳{$record->sellerPayableAmount()} is on hold for 48 hours in case the buyer reports a problem, then moves to your balance."
                                : "Order #{$record->sub_order_number} updated to {$statusName}.";

                            Notification::make()
                                ->title('Courier Status Updated')
                                ->body($message)
                                ->success()
                                ->send();
                        }),

                    // 4. Print 4x6 Thermal Shipping Label
                    Action::make('printShippingLabel')
                        ->visible(fn (SubOrder $record) => $record->isClearedForDispatch())
                        ->label('Shipping Label')
                        ->icon('heroicon-o-printer')
                        ->color('gray')
                        ->url(fn (SubOrder $record) => route('seller.sub_order.label', $record->sub_order_number))
                        ->openUrlInNewTab(),

                    // 5. Print Commercial Delivery Challan (A4)
                    Action::make('printDeliveryChallan')
                        ->visible(fn (SubOrder $record) => $record->isClearedForDispatch())
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
                        ->visible(fn (SubOrder $record) => $record->isClearedForDispatch() && ! $record->is_self_delivered && in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
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
                            try {
                                app(SelfDeliveryService::class)->dispatchSelfDelivery(
                                    subOrder: $record,
                                    hubLocation: $data['hub_location'],
                                    driverContact: $data['driver_contact'],
                                    dispatchNote: $data['dispatch_note']
                                );
                            } catch (InvalidStatusTransitionException|DomainException $exception) {
                                Notification::make()->title('Not dispatched')->body($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()
                                ->title('Dispatched via Factory Direct Fleet!')
                                // Never show the code to the seller: only the buyer can release it (D5, final review C1)
                                ->body('A 6-digit handover code is on the buyer\'s tracking page. Ask the recipient for it at delivery.')
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
                                    ->body("Handover code verified. Order #{$record->sub_order_number} marked as delivered. ৳{$record->sellerPayableAmount()} is on hold for 48 hours in case the buyer reports a problem, then moves to your balance.")
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
                        ->label('Book Courier Pickup (test only)')
                        ->icon('heroicon-o-calendar-days')
                        ->color('indigo')
                        // Makes up a tracking number until a courier API is connected, so never offered on a live site
                        ->visible(fn (SubOrder $record) => app()->environment('local', 'testing') && $record->isClearedForDispatch() && in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing']))
                        ->schema([
                            Select::make('courier_name')
                                ->label('Courier Provider')
                                ->options([
                                    'Steadfast Courier' => 'Steadfast Courier (booked automatically)',
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
                                ->default('Wholesale goods, handle with care.')
                                ->required(),
                        ])
                        ->action(function (SubOrder $record, array $data): void {
                            try {
                                $updated = app(CourierBookingService::class)->schedulePickup($record, $data);
                            } catch (InvalidStatusTransitionException|DomainException $exception) {
                                Notification::make()->title('Pickup not booked')->body($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()
                                ->title('Courier Pickup Scheduled!')
                                ->body("Tracking number {$updated->tracking_number} assigned by {$data['courier_name']}.")
                                ->success()
                                ->send();
                        }),

                ])
                    ->label('Fulfil')
                    ->icon('heroicon-m-truck')
                    ->button()
                    ->size('sm')
                    ->color('gray'),
            ]);
    }
}
