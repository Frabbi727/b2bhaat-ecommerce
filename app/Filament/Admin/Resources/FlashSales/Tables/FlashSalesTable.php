<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Tables;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\FlashSale;
use App\Services\Promotions\FlashSaleLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FlashSalesTable
{
    public static function configure(Table $table): Table
    {
        $service = app(FlashSaleLifecycleService::class);

        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (FlashSale $record): string => $record->slug),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (FlashSale $record): string => $record->effectiveStatus()->label())
                    ->color(fn (FlashSale $record): string => match ($record->effectiveStatus()) {
                        FlashSaleStatus::Draft => 'gray',
                        FlashSaleStatus::Scheduled => 'info',
                        FlashSaleStatus::Active => 'success',
                        FlashSaleStatus::Paused => 'warning',
                        FlashSaleStatus::Ended, FlashSaleStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('registration')
                    ->label('Registration')
                    ->badge()
                    ->state(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'Open';
                        }
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'Upcoming';
                        }

                        return 'Closed';
                    })
                    ->color(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'success';
                        }
                        if ($record->registration_starts_at && now()->lt($record->registration_starts_at)) {
                            return 'info';
                        }

                        return 'gray';
                    }),

                TextColumn::make('discount_range')
                    ->label('Discounts')
                    ->state(fn (FlashSale $record): string => (int) ($record->min_discount_percentage ?? 5).'% - '.(int) ($record->max_discount_percentage ?? 80).'%')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('starts_at')
                    ->label('Starts (Dhaka)')
                    ->dateTime('d M Y, h:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Ends (Dhaka)')
                    ->dateTime('d M Y, h:i A', 'Asia/Dhaka')
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),

                TextColumn::make('units_sold')
                    ->label('Units Sold')
                    ->state(fn (FlashSale $record): int => (int) $record->items->sum(fn ($item) => $item->unitsSold())),

                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'active' => 'Active',
                        'paused' => 'Paused',
                        'ended' => 'Ended',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                Action::make('endNow')
                    ->label('Turn off now')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Turn off flash sale now?')
                    ->modalDescription('Flash prices stop immediately. Orders already placed keep their price.')
                    ->visible(fn (FlashSale $record): bool => in_array($record->effectiveStatus(), [FlashSaleStatus::Active, FlashSaleStatus::Paused], true))
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->endNow($record);
                            Notification::make()->danger()->title('Flash sale ended.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot end campaign')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('pause')
                    ->label('Pause')
                    ->color('warning')
                    ->visible(fn (FlashSale $record): bool => in_array($record->effectiveStatus(), [FlashSaleStatus::Scheduled, FlashSaleStatus::Active], true) && $record->status !== FlashSaleStatus::Paused)
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->pause($record);
                            Notification::make()->warning()->title('Flash sale paused.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot pause campaign')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('resume')
                    ->label('Resume')
                    ->color('success')
                    ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Paused && $record->ends_at->gt(now()))
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->resume($record);
                            Notification::make()->success()->title('Flash sale resumed.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot resume campaign')->body($e->getMessage())->send();
                        }
                    }),

                EditAction::make(),

                DeleteAction::make()
                    ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Draft),
            ]);
    }
}
