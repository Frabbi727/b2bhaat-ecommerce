<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\FlashSales\RelationManagers;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Exceptions\FlashSaleSubmissionException;
use App\Filament\Seller\Resources\FlashSales\FlashSaleResource;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Services\Formatting\Money;
use App\Services\Promotions\FlashSaleSubmissionService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ->headerActions([
                Action::make('submitProduct')
                    ->label('Submit Product to Campaign')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(function (): bool {
                        $sale = $this->getOwnerRecord();

                        return $sale instanceof FlashSale && $sale->isRegistrationOpen();
                    })
                    ->form(function (): array {
                        /** @var FlashSale $sale */
                        $sale = $this->getOwnerRecord();

                        return FlashSaleResource::getProductSubmissionForm($sale);
                    })
                    ->action(function (array $data, Action $action): void {
                        /** @var FlashSale $sale */
                        $sale = $this->getOwnerRecord();
                        try {
                            app(FlashSaleSubmissionService::class)->submit(auth()->user(), $sale, $data);
                            Notification::make()
                                ->title('Product submitted successfully for review')
                                ->success()
                                ->send();
                        } catch (FlashSaleSubmissionException|DomainException $e) {
                            Notification::make()
                                ->title('Submission failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->columns([
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('original_retail_price')
                    ->label('Original Price')
                    ->formatStateUsing(fn ($state): string => Money::taka($state)),

                TextColumn::make('campaign_price')
                    ->label('Campaign Price')
                    ->formatStateUsing(fn ($state): string => Money::taka($state)),

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
                    ->formatStateUsing(fn ($state): string => Money::taka($state)),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('rejection_reason')
                    ->label('Admin Feedback')
                    ->placeholder('—')
                    ->tooltip(fn (FlashSaleProduct $record): ?string => $record->rejection_reason)
                    ->limit(50),
            ])
            ->recordActions([
                Action::make('editOffer')
                    ->label('Edit Offer')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (FlashSaleProduct $record): bool => in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::RevisionRequested], true) && ($record->flashSale?->isRegistrationOpen() ?? false))
                    ->fillForm(fn (FlashSaleProduct $record): array => [
                        'discount_type' => $record->discount_type instanceof FlashSaleDiscountType ? $record->discount_type->value : (string) $record->discount_type,
                        'discount_value' => (float) $record->discount_value,
                        'stock_limit' => $record->stock_limit,
                        'per_customer_limit' => $record->per_customer_limit,
                        'seller_notes' => $record->seller_notes,
                    ])
                    ->form(fn (FlashSaleProduct $record): array => [
                        Select::make('discount_type')
                            ->label('Discount Type')
                            ->options(FlashSaleDiscountType::class)
                            ->required(),
                        TextInput::make('discount_value')
                            ->label('Discount Value')
                            ->numeric()
                            ->required()
                            ->helperText("Campaign discount must be between {$record->flashSale?->min_discount_percentage}% and {$record->flashSale?->max_discount_percentage}%"),
                        TextInput::make('stock_limit')
                            ->label('Allocated Stock')
                            ->numeric()
                            ->required()
                            ->helperText("Minimum {$record->flashSale?->min_stock_per_product} units required."),
                        TextInput::make('per_customer_limit')
                            ->label('Per Customer Limit')
                            ->numeric()
                            ->nullable(),
                        Textarea::make('seller_notes')
                            ->label('Notes for Reviewer')
                            ->rows(2)
                            ->nullable(),
                    ])
                    ->action(function (FlashSaleProduct $record, array $data, Action $action): void {
                        try {
                            app(FlashSaleSubmissionService::class)->updateSubmission(auth()->user(), $record, $data);
                            Notification::make()
                                ->title('Submission Updated')
                                ->body('Your revised offer has been submitted for admin review.')
                                ->success()
                                ->send();
                        } catch (FlashSaleSubmissionException $e) {
                            Notification::make()
                                ->title('Update Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),

                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Withdraw Product from Campaign')
                    ->modalDescription('Are you sure you want to withdraw this product? It will no longer participate in the flash sale.')
                    ->visible(fn (FlashSaleProduct $record): bool => in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::RevisionRequested, FlashSaleSubmissionStatus::Approved], true) && $record->flashSale && $record->flashSale->starts_at->isFuture())
                    ->action(function (FlashSaleProduct $record): void {
                        if (! $record->flashSale?->starts_at?->isFuture() || ! in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::RevisionRequested, FlashSaleSubmissionStatus::Approved], true)) {
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
