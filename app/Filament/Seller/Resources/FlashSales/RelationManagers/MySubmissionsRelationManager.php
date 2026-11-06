<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\FlashSales\RelationManagers;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\FlashSaleProduct;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MySubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'My Submitted Products';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('seller_id', auth()->id())->with(['product', 'flashSale']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('original_retail_price')
                    ->label('Original Price')
                    ->money('BDT'),

                TextColumn::make('campaign_price')
                    ->label('Campaign Price')
                    ->money('BDT'),

                TextColumn::make('discount_value')
                    ->label('Discount')
                    ->formatStateUsing(function ($state, FlashSaleProduct $record): string {
                        $type = $record->discount_type instanceof FlashSaleDiscountType
                            ? $record->discount_type
                            : FlashSaleDiscountType::tryFrom((string) $record->discount_type);

                        $val = (float) $state;

                        return $type === FlashSaleDiscountType::Percentage
                            ? "{$val}%"
                            : '৳'.number_format($val, 2);
                    }),

                TextColumn::make('stock_limit')
                    ->label('Allocated Quota'),

                TextColumn::make('units_sold')
                    ->label('Sold'),

                TextColumn::make('revenue')
                    ->label('Revenue Generated')
                    ->state(fn (FlashSaleProduct $record): float => (float) ($record->campaign_price * $record->units_sold))
                    ->money('BDT'),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('rejection_reason')
                    ->label('Admin Feedback')
                    ->placeholder('—')
                    ->tooltip(fn (FlashSaleProduct $record): ?string => $record->rejection_reason)
                    ->limit(50),
            ])
            ->recordActions([
                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Withdraw Product from Campaign')
                    ->modalDescription('Are you sure you want to withdraw this product? It will no longer participate in the flash sale.')
                    ->visible(fn (FlashSaleProduct $record): bool => in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::Approved], true) && $record->flashSale && $record->flashSale->starts_at->isFuture())
                    ->action(function (FlashSaleProduct $record): void {
                        if (! $record->flashSale?->starts_at?->isFuture() || ! in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::Approved], true)) {
                            Notification::make()
                                ->title('Cannot withdraw submission')
                                ->body('Submissions can only be withdrawn before the campaign starts.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['status' => FlashSaleSubmissionStatus::Withdrawn]);

                        Notification::make()
                            ->title('Submission withdrawn successfully')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
