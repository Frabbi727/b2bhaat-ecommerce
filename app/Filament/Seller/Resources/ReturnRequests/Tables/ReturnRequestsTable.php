<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Tables;

use App\Models\ReturnRequest;
use App\Services\Orders\OrderDisputeService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReturnRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('items.orderItem.product:id,title'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Request Date')
                    ->dateTime('d M Y')
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
                    ->label('Reason')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(str_replace('_', ' ', $state))),
                TextColumn::make('items_summary')
                    ->label('Items coming back')
                    ->state(fn (ReturnRequest $record): string => $record->itemsSummary())
                    ->wrap(),
                TextColumn::make('requested_action')
                    ->label('Desired Resolution')
                    ->badge()
                    ->color(fn (ReturnRequest $record): array => match ($record->requested_action) {
                        'replacement' => Color::Blue,
                        default => Color::Indigo,
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ReturnRequest $record): array => match ($record->status) {
                        'approved' => Color::Emerald,
                        'rejected' => Color::Rose,
                        default => Color::Amber,
                    }),
                TextColumn::make('description')
                    ->visibleFrom('md')
                    ->label('Buyer Details')
                    ->limit(35),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve Return')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ReturnRequest $record) => $record->isPending())
                    // Item 8: on a parcel whose COD cash the seller kept, B2Bhaat refunds the buyer and charges the seller
                    ->modalDescription(fn (ReturnRequest $record): ?string => $record->requested_action === 'refund' && $record->subOrder?->is_self_delivered && $record->subOrder->order?->paymentMethod?->slug === 'cod'
                        ? 'You collected this buyer\'s cash yourself. B2Bhaat refunds the buyer and takes the refund from your balance, which can go below zero.'
                        : null)
                    ->form([
                        Textarea::make('note')
                            ->label('Seller Approval Note & Return Instructions')
                            ->default('Return request accepted. Replacement item will be dispatched or refund credited.')
                            ->required(),
                    ])
                    ->action(function (ReturnRequest $record, array $data, Action $action): void {
                        try {
                            app(OrderDisputeService::class)->resolveReturnRequest($record, ReturnRequest::STATUS_APPROVED, $data['note']);
                        } catch (DomainException $exception) {
                            Notification::make()->title('Return not approved')->body($exception->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()
                            ->title('Return Approved')
                            ->body("Return request for #{$record->subOrder?->sub_order_number} has been approved.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (ReturnRequest $record) => $record->isPending())
                    ->form([
                        Textarea::make('note')
                            ->label('Reason for Rejection')
                            ->required()
                            ->placeholder('Explain why the dispute does not meet return guidelines...'),
                    ])
                    ->action(function (ReturnRequest $record, array $data): void {
                        app(OrderDisputeService::class)->resolveReturnRequest($record, ReturnRequest::STATUS_REJECTED, $data['note']);

                        Notification::make()
                            ->title('Dispute Rejected')
                            ->body("Return request for #{$record->subOrder?->sub_order_number} was rejected.")
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
