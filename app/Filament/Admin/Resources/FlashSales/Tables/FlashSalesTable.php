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
use Illuminate\Database\Eloquent\Builder;

class FlashSalesTable
{
    public static function configure(Table $table): Table
    {
        $service = app(FlashSaleLifecycleService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withUnitsSold())
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
                    ->color(fn (FlashSale $record): string|array|null => $record->effectiveStatus()->getColor()),

                TextColumn::make('registration')
                    ->label('Registration')
                    ->badge()
                    ->state(function (FlashSale $record): string {
                        if ($record->isRegistrationOpen()) {
                            return 'Open';
                        }
                        if ($record->status === FlashSaleStatus::Reviewing) {
                            return 'In Review';
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
                        if ($record->status === FlashSaleStatus::Reviewing) {
                            return 'warning';
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
                    ->state(fn (FlashSale $record): int => (int) $record->units_sold_total),

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
                Action::make('openEnrollment')
                    ->label('Start Accepting Submissions')
                    ->tooltip('Open this campaign for sellers to submit products')
                    ->icon('heroicon-o-user-group')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Open Campaign for Seller Submissions')
                    ->modalDescription('This will open the campaign for sellers to view in Seller Center and submit products.')
                    ->modalSubmitActionLabel('Open Submissions')
                    ->visible(fn (FlashSale $record): bool => in_array($record->status, [FlashSaleStatus::Draft, FlashSaleStatus::Reviewing], true))
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->openForEnrollment($record);
                            Notification::make()->success()->title('Opened for seller participation.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot open enrollment')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('closeEnrollment')
                    ->label('Close Submissions')
                    ->tooltip('Close submissions and move to admin review')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Close Seller Submissions')
                    ->modalDescription('Are you sure you want to close seller submissions? Sellers will no longer be able to submit new products for this campaign.')
                    ->modalSubmitActionLabel('Close Submissions')
                    ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Enrolling)
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->closeEnrollment($record);
                            Notification::make()->success()->title('Submissions closed. In review.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot close enrollment')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (FlashSale $record): bool => in_array($record->status, [FlashSaleStatus::Draft, FlashSaleStatus::Enrolling, FlashSaleStatus::Reviewing], true))
                    ->action(function (FlashSale $record) use ($service): void {
                        try {
                            $service->publish($record);
                            Notification::make()->success()->title('Campaign published and scheduled.')->send();
                        } catch (InvalidStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();
                        }
                    }),
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
