<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Tables;

use App\Filament\Admin\Support\ApplicationReviewActions;
use App\Models\SellerProfile;
use App\Notifications\Seller\SellerContactChangeDecided;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SellerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user')->withCount('products'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('store_name')
                    ->label('Store')
                    ->description(fn (SellerProfile $record): ?string => $record->user?->name)
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('trade_license_number')
                    ->label('Trade licence')
                    ->placeholder('Not provided')
                    ->copyable()
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('commission_rate_percentage')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                    ->sortable(),

                TextColumn::make('contact_change_submitted_at')
                    ->label('Address change')
                    ->state(fn (SellerProfile $record): ?string => $record->hasPendingContactChange() ? 'Waiting' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->state(fn (SellerProfile $record): string => ApplicationReviewActions::sellerStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    }),

                IconColumn::make('is_on_vacation')
                    ->label('On break')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedPauseCircle)
                    ->falseIcon(null)
                    ->trueColor('warning')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Applied')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('pending_contact_change')
                    ->label('Address change waiting for review')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('contact_change_submitted_at')),
            ])
            ->recordActions([
                ApplicationReviewActions::approveSeller()->button(),
                self::reviewContactChange(),
                ActionGroup::make([
                    ApplicationReviewActions::viewSellerApplication(),
                    Action::make('viewStorefront')
                        ->label('View storefront')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->color('gray')
                        ->url(fn (SellerProfile $record): string => url('/store/'.$record->store_slug))
                        ->openUrlInNewTab()
                        ->visible(fn (SellerProfile $record): bool => $record->isApproved() && filled($record->store_slug)),
                    EditAction::make(),
                    ApplicationReviewActions::rejectSeller(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading('No sellers here')
            ->emptyStateDescription('Try another tab. New store applications land in “Waiting for review”.');
        // No delete: sellers own products, orders and payouts (PRA-044)
    }

    /**
     * Compare the seller's current and requested address/phone, then approve or reject the request.
     */
    public static function reviewContactChange(): Action
    {
        $decide = function (SellerProfile $record, bool $approved): void {
            $approved ? $record->approveContactChange() : $record->rejectContactChange();

            if ($record->user) {
                try {
                    $record->user->notify(new SellerContactChangeDecided($record, $approved));
                } catch (Throwable $e) {
                    report($e);
                }
            }

            Notification::make()
                ->title($approved ? 'New address approved' : 'Address change rejected')
                ->success()
                ->send();
        };

        return Action::make('reviewContactChange')
            ->label('Review address')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('warning')
            ->button()
            ->visible(fn (SellerProfile $record): bool => $record->hasPendingContactChange())
            ->modalHeading(fn (SellerProfile $record): string => "Address change for {$record->store_name}")
            ->modalDescription('The new details print on this seller\'s documents once approved.')
            ->schema([
                TextEntry::make('current')
                    ->label('Now')
                    ->state(fn (SellerProfile $record): array => collect([$record->business_address, $record->business_phone])->filter()->all())
                    ->listWithLineBreaks()
                    ->placeholder('Nothing set'),
                TextEntry::make('requested')
                    ->label('Requested')
                    ->state(fn (SellerProfile $record): array => collect([$record->pending_business_address, $record->pending_business_phone])->filter()->all())
                    ->listWithLineBreaks()
                    ->placeholder('Remove both')
                    ->weight('medium'),
            ])
            ->modalSubmitActionLabel('Approve')
            ->action(fn (SellerProfile $record) => $decide($record, true))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('reject', arguments: ['reject' => true])
                    ->label('Reject')
                    ->color('danger'),
            ])
            ->before(function (SellerProfile $record, array $arguments, Action $action) use ($decide): void {
                if ($arguments['reject'] ?? false) {
                    $decide($record, false);
                    $action->cancel();
                }
            });
    }
}
