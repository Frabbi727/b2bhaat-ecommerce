<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReturnRequests;

use App\Filament\Admin\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Models\ReturnRequest;
use App\Services\Orders\OrderDisputeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every return / problem report across sellers. When a seller does not answer, the admin decides,
 * through the same OrderDisputeService the seller uses, so stock and refunds follow the same rules.
 */
class ReturnRequestResource extends Resource
{
    protected static ?string $model = ReturnRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static UnitEnum|string|null $navigationGroup = 'Orders & Fulfilment';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Returns & disputes';

    protected static ?string $modelLabel = 'return request';

    protected static ?string $pluralModelLabel = 'returns & disputes';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subOrder.seller.sellerProfile', 'customer', 'items.orderItem.product:id,title']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subOrder.sub_order_number')->label('Parcel')->searchable()->copyable(),
                TextColumn::make('subOrder.seller.sellerProfile.store_name')->label('Seller'),
                TextColumn::make('customer.name')->label('Buyer')->searchable(),
                TextColumn::make('reason')->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),
                TextColumn::make('items_summary')->label('Items coming back')->state(fn (ReturnRequest $record): string => $record->itemsSummary())->wrap(),
                TextColumn::make('requested_action')->label('Wants'),
                TextColumn::make('description')->label('Buyer says')->wrap()->limit(80),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    ReturnRequest::STATUS_PENDING => 'warning',
                    ReturnRequest::STATUS_APPROVED, ReturnRequest::STATUS_RESOLVED => 'success',
                    default => 'danger',
                }),
                TextColumn::make('seller_note')->label('Decision note')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('created_at')->label('Reported')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ReturnRequest::STATUS_PENDING => 'Waiting for a decision',
                    ReturnRequest::STATUS_APPROVED => 'Approved',
                    ReturnRequest::STATUS_REJECTED => 'Rejected',
                    ReturnRequest::STATUS_RESOLVED => 'Resolved',
                ]),
            ])
            ->recordActions([
                self::decisionAction('approve', 'Approve', ReturnRequest::STATUS_APPROVED, 'success', 'heroicon-o-check'),
                self::decisionAction('reject', 'Reject', ReturnRequest::STATUS_REJECTED, 'danger', 'heroicon-o-x-mark'),
            ]);
    }

    private static function decisionAction(string $name, string $label, string $status, string $color, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (ReturnRequest $record): bool => $record->isPending())
            ->modalDescription('You are deciding instead of the seller. The buyer and seller both see your note.')
            ->schema([
                Textarea::make('note')->label('Your note')->required()->minLength(5),
            ])
            ->action(function (ReturnRequest $record, array $data) use ($status): void {
                app(OrderDisputeService::class)->resolveReturnRequest($record, $status, $data['note']);
                $record->update(['admin_arbitration_note' => $data['note']]);

                Notification::make()->title($status === ReturnRequest::STATUS_APPROVED ? 'Return approved' : 'Return rejected')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnRequests::route('/'),
        ];
    }
}
