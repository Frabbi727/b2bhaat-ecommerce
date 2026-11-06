<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\RelationManagers;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\FlashSaleProduct;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Seller Submissions & Moderation';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'seller']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.title')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('seller.name')
                    ->label('Seller Store')
                    ->placeholder('—')
                    ->searchable(),

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
                    ->label('Allocated Stock'),

                TextColumn::make('units_sold')
                    ->label('Sold'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof FlashSaleSubmissionStatus ? $state : FlashSaleSubmissionStatus::tryFrom((string) $state)) {
                        FlashSaleSubmissionStatus::Pending => 'warning',
                        FlashSaleSubmissionStatus::Approved => 'success',
                        FlashSaleSubmissionStatus::Rejected => 'danger',
                        FlashSaleSubmissionStatus::Withdrawn => 'gray',
                        FlashSaleSubmissionStatus::Paused => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(50)
                    ->tooltip(fn (FlashSaleProduct $record): ?string => $record->rejection_reason)
                    ->toggleable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FlashSaleSubmissionStatus::class),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FlashSaleProduct $record): bool => in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::Rejected], true))
                    ->action(function (FlashSaleProduct $record): void {
                        $record->update([
                            'status' => FlashSaleSubmissionStatus::Approved,
                            'rejection_reason' => null,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Submission Approved')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (FlashSaleProduct $record): bool => $record->status !== FlashSaleSubmissionStatus::Rejected)
                    ->form([
                        Textarea::make('rejection_reason')
                            ->required()
                            ->label('Feedback / Reason for Rejection'),
                    ])
                    ->action(function (FlashSaleProduct $record, array $data): void {
                        $record->update([
                            'status' => FlashSaleSubmissionStatus::Rejected,
                            'rejection_reason' => $data['rejection_reason'],
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Submission Rejected')
                            ->danger()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve Selected Submissions')
                        ->modalDescription('Are you sure you want to approve the selected pending/rejected seller submissions?')
                        ->action(function (Collection $records): void {
                            $approvedCount = 0;
                            $records
                                ->filter(fn (FlashSaleProduct $record): bool => in_array($record->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::Rejected], true))
                                ->each(function (FlashSaleProduct $record) use (&$approvedCount): void {
                                    $record->update([
                                        'status' => FlashSaleSubmissionStatus::Approved,
                                        'rejection_reason' => null,
                                        'reviewed_by' => auth()->id(),
                                        'reviewed_at' => now(),
                                    ]);
                                    $approvedCount++;
                                });

                            Notification::make()
                                ->title("{$approvedCount} submission(s) approved")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
