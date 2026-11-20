<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Tables;

use App\Models\ReturnRequest;
use App\Services\Orders\OrderDisputeService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReturnRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['items.orderItem.product:id,title', 'subOrder:id,sub_order_number', 'customer:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Asked')
                    ->since()
                    ->dateTimeTooltip('d M Y, g:i A')
                    ->sortable(),
                TextColumn::make('subOrder.sub_order_number')
                    ->label('Package #')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->visibleFrom('md')
                    ->label('Buyer')
                    ->searchable(),
                TextColumn::make('reason')
                    ->visibleFrom('md')
                    ->label('Why')
                    ->formatStateUsing(fn (ReturnRequest $record): string => $record->reasonLabel()),
                TextColumn::make('items_summary')
                    ->label('Coming back')
                    ->state(fn (ReturnRequest $record): string => $record->itemsSummary())
                    ->wrap(),
                TextColumn::make('requested_action')
                    ->label('Buyer wants')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'refund' ? 'Refund' : 'Replacement')
                    ->color(fn (string $state): string => $state === 'refund' ? 'warning' : 'info'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReturnRequest $record): string => self::statusLabel($record))
                    ->color(fn (ReturnRequest $record): string => self::statusColor($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->stackedOnMobile()
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square')
            ->emptyStateHeading('No returns')
            ->emptyStateDescription('When a buyer asks to return something after delivery, it shows up here for you to approve or reject.')
            // Approve and reject live on the return's own page, where the buyer's reason and the items are in view
            ->recordActions([
                ViewAction::make()
                    ->label(fn (ReturnRequest $record): string => $record->isPending() ? 'Review' : 'Open')
                    ->button()
                    ->color(fn (ReturnRequest $record): string => $record->isPending() ? 'primary' : 'gray'),
            ]);
    }

    /** "Nail set × 3" or "the whole parcel", to sit inside a sentence. */
    public static function whatComesBack(ReturnRequest $record): string
    {
        $summary = $record->itemsSummary();

        return $summary === 'Whole parcel' ? 'the whole parcel' : $summary;
    }

    public static function statusLabel(ReturnRequest $record): string
    {
        return match ($record->status) {
            ReturnRequest::STATUS_PENDING => 'Needs your answer',
            ReturnRequest::STATUS_APPROVED => 'Approved',
            ReturnRequest::STATUS_REJECTED => 'Rejected',
            ReturnRequest::STATUS_RESOLVED => 'Closed',
            default => str($record->status)->headline()->toString(),
        };
    }

    public static function statusColor(ReturnRequest $record): string
    {
        return match ($record->status) {
            ReturnRequest::STATUS_PENDING => 'warning',
            ReturnRequest::STATUS_APPROVED => 'success',
            ReturnRequest::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Approve and reject, shared by the returns list and a return's own page.
     *
     * @return list<Action>
     */
    public static function decisionActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (ReturnRequest $record) => $record->isPending())
                ->modalHeading(fn (ReturnRequest $record): string => $record->wantsRefund() ? 'Approve the refund?' : 'Approve the replacement?')
                ->modalDescription(function (ReturnRequest $record): string {
                    // Item 8: on a parcel whose COD cash the seller kept, B2Bhaat refunds the buyer and charges the seller
                    if ($record->wantsRefund() && $record->subOrder?->is_self_delivered && $record->subOrder->order?->paymentMethod?->slug === 'cod') {
                        return 'You collected this buyer\'s cash yourself. B2Bhaat refunds the buyer and takes the refund from your balance, which can go below zero.';
                    }

                    return $record->wantsRefund()
                        ? 'The buyer gets their money back for '.self::whatComesBack($record).', and it comes out of your earnings for this order. This cannot be undone.'
                        : 'A free parcel with '.self::whatComesBack($record).' is added to your Orders for you to send. This cannot be undone.';
                })
                ->modalSubmitActionLabel('Approve')
                ->schema([
                    Textarea::make('note')
                        ->label('Your note (kept on record)')
                        ->placeholder('Optional, e.g. "Checked the photos, item is damaged"')
                        ->maxLength(500),
                ])
                ->action(function (ReturnRequest $record, array $data, Action $action): void {
                    try {
                        app(OrderDisputeService::class)->resolveReturnRequest($record, ReturnRequest::STATUS_APPROVED, filled($data['note'] ?? null) ? $data['note'] : 'Approved by the seller.');
                    } catch (DomainException $exception) {
                        Notification::make()->title('Return not approved')->body($exception->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()
                        ->title('Return approved')
                        ->body($record->wantsRefund()
                            ? "The buyer of #{$record->subOrder?->sub_order_number} will get their refund."
                            : "A free replacement parcel for #{$record->subOrder?->sub_order_number} is in your Orders, ready to pack.")
                        ->success()
                        ->send();
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (ReturnRequest $record) => $record->isPending())
                ->modalHeading('Reject this return?')
                ->modalDescription('The return is closed and the buyer keeps the items. Say why, so B2Bhaat can see your reason if the buyer complains.')
                ->modalSubmitActionLabel('Reject')
                ->schema([
                    Textarea::make('note')
                        ->label('Why are you rejecting it?')
                        ->required()
                        ->maxLength(500)
                        ->placeholder('e.g. The photos show the item was used'),
                ])
                ->action(function (ReturnRequest $record, array $data): void {
                    app(OrderDisputeService::class)->resolveReturnRequest($record, ReturnRequest::STATUS_REJECTED, $data['note']);

                    Notification::make()
                        ->title('Return rejected')
                        ->body("The return for #{$record->subOrder?->sub_order_number} is closed.")
                        ->warning()
                        ->send();
                }),
        ];
    }
}
