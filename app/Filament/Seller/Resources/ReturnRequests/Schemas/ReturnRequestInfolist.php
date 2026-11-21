<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Schemas;

use App\Filament\Seller\Resources\ReturnRequests\Tables\ReturnRequestsTable;
use App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderInfolist;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\SubOrder;
use App\Services\Formatting\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The page a seller reads to decide a return: what the buyer wants and why, what is coming back, and what
 * approving or rejecting will do. Every value is read-only; the decision is the Approve / Reject buttons.
 */
class ReturnRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewEntry::make('decision_overview')
                    ->hiddenLabel()
                    ->view('filament.seller.return-requests.decision-overview')
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            self::buyerSaysSection(),
                            self::itemsSection(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            self::orderSection(),
                            self::decisionSection(),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    protected static function buyerSaysSection(): Section
    {
        return Section::make('What the buyer says')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->schema([
                TextEntry::make('reason')
                    ->label('Reason')
                    ->formatStateUsing(fn (ReturnRequest $record): string => $record->reasonLabel())
                    ->weight('medium'),
                TextEntry::make('description')
                    ->label('In their words')
                    ->placeholder('The buyer did not add any details.')
                    ->prose(),
            ]);
    }

    protected static function itemsSection(): Section
    {
        return Section::make(fn (ReturnRequest $record): string => $record->items->isEmpty() ? 'Coming back: the whole parcel' : 'Coming back')
            ->description(fn (ReturnRequest $record): string => 'Worth '.Money::taka(collect(self::lines($record))->sum('total')).' at the price the buyer paid')
            ->icon('heroicon-o-arrow-uturn-left')
            ->schema([
                ViewEntry::make('return_items')
                    ->hiddenLabel()
                    ->view('filament.seller.return-requests.items'),
            ]);
    }

    protected static function orderSection(): Section
    {
        return Section::make('The order')
            ->icon('heroicon-o-shopping-bag')
            ->schema([
                TextEntry::make('subOrder.sub_order_number')
                    ->label('Package no.')
                    ->fontFamily('mono')
                    ->url(fn (ReturnRequest $record): ?string => $record->subOrder ? SubOrderResource::getUrl('view', ['record' => $record->subOrder]) : null)
                    ->color('primary')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after'),
                TextEntry::make('customer.name')
                    ->label('Buyer')
                    ->placeholder('—'),
                TextEntry::make('delivered')
                    ->label('Delivered')
                    ->state(fn (ReturnRequest $record): ?string => $record->subOrder?->fulfillmentStatus?->slug === 'delivered' && ($deliveredAt = $record->subOrder->deliveredAt())
                        ? SubOrderInfolist::localTime($deliveredAt)->format('d M Y, g:i A')
                        : null)
                    ->placeholder(fn (ReturnRequest $record): string => $record->subOrder?->fulfillmentStatus?->name ?? '—'),
                TextEntry::make('subOrder.order.paymentMethod.name')
                    ->label('Paid by')
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Return asked')
                    ->dateTime('d M Y, g:i A'),
            ]);
    }

    protected static function decisionSection(): Section
    {
        return Section::make('Decision')
            ->icon('heroicon-o-scale')
            ->visible(fn (ReturnRequest $record): bool => ! $record->isPending())
            ->schema([
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReturnRequest $record): string => ReturnRequestsTable::statusLabel($record))
                    ->color(fn (ReturnRequest $record): string => ReturnRequestsTable::statusColor($record)),
                TextEntry::make('resolved_at')
                    ->label('Decided')
                    ->dateTime('d M Y, g:i A')
                    ->placeholder('—'),
                TextEntry::make('seller_note')
                    ->label('Note')
                    ->placeholder('No note'),
                TextEntry::make('admin_arbitration_note')
                    ->label('B2B Haat\'s note')
                    ->visible(fn (ReturnRequest $record): bool => filled($record->admin_arbitration_note)),
                TextEntry::make('replacement')
                    ->label('Replacement parcel')
                    ->state(fn (ReturnRequest $record): ?string => self::replacementParcel($record)?->sub_order_number)
                    ->fontFamily('mono')
                    ->url(fn (ReturnRequest $record): ?string => ($parcel = self::replacementParcel($record)) ? SubOrderResource::getUrl('view', ['record' => $parcel]) : null)
                    ->color('primary')
                    ->visible(fn (ReturnRequest $record): bool => self::replacementParcel($record) !== null),
            ]);
    }

    /**
     * What is coming back: the chosen items, or every item in the parcel when the request covers all of it.
     *
     * @return list<array{item: OrderItem, quantity: int, total: float}>
     */
    public static function lines(ReturnRequest $record): array
    {
        if ($record->items->isNotEmpty()) {
            return $record->items
                ->filter(fn (ReturnRequestItem $line): bool => $line->orderItem !== null)
                ->map(fn (ReturnRequestItem $line): array => [
                    'item' => $line->orderItem,
                    'quantity' => (int) $line->quantity,
                    'total' => round((float) $line->orderItem->unit_price * (int) $line->quantity, 2),
                ])
                ->values()
                ->all();
        }

        return ($record->subOrder?->items ?? collect())
            ->map(fn (OrderItem $item): array => ['item' => $item, 'quantity' => (int) $item->quantity, 'total' => (float) $item->total_price])
            ->values()
            ->all();
    }

    public static function replacementParcel(ReturnRequest $record): ?SubOrder
    {
        return $record->subOrder?->replacements->firstWhere('replaces_return_request_id', $record->id);
    }

    /**
     * Where the return stands, and for an open one, exactly what each choice does.
     *
     * @return array{tone: string, icon: string, heading: string, body: string, status: string, statusColor: string, points?: array<string, string>}
     */
    public static function decisionSummary(ReturnRequest $record): array
    {
        $what = ReturnRequestsTable::whatComesBack($record);
        $worth = Money::taka(collect(self::lines($record))->sum('total'));
        $status = ReturnRequestsTable::statusLabel($record);
        $statusColor = ReturnRequestsTable::statusColor($record);
        $base = ['status' => $status, 'statusColor' => $statusColor];

        if ($record->isPending()) {
            $sellerKeptCash = $record->subOrder?->is_self_delivered && $record->subOrder->order?->paymentMethod?->slug === 'cod';

            return $base + [
                'tone' => 'warning',
                'icon' => $record->wantsRefund() ? 'heroicon-o-banknotes' : 'heroicon-o-arrow-path',
                'heading' => $record->wantsRefund() ? 'The buyer wants a refund' : 'The buyer wants a replacement',
                'body' => 'Reason: '.$record->reasonLabel().'. Read what they say and check the items below, then approve or reject.',
                'points' => [
                    'If you approve' => $record->wantsRefund()
                        ? "The buyer gets their money back for {$what} (worth {$worth}). It comes out of ".($sellerKeptCash ? 'your balance, because you collected the cash yourself.' : 'your earnings for this order.')
                        : "You send {$what} again for free. A new ৳0 parcel appears in your Orders to pack and ship.",
                    'If you reject' => 'The return is closed and the buyer keeps the items. You must give a reason, which B2B Haat can see.',
                ],
            ];
        }

        return $base + match ($record->status) {
            ReturnRequest::STATUS_APPROVED => $record->wantsRefund()
                ? ['tone' => 'success', 'icon' => 'heroicon-o-check-circle', 'heading' => 'Refund approved',
                    'body' => "The buyer is refunded for {$what}. Nothing more to do."]
                : ['tone' => 'primary', 'icon' => 'heroicon-o-truck', 'heading' => 'Replacement approved',
                    'body' => self::replacementParcel($record)
                        ? 'Send the free replacement parcel '.self::replacementParcel($record)->sub_order_number.' from your Orders, like any other order.'
                        : "Send {$what} again from your Orders."],
            ReturnRequest::STATUS_ESCALATED => ['tone' => 'warning', 'icon' => 'heroicon-o-scale', 'heading' => 'B2Bhaat is reviewing this return',
                'body' => 'You rejected it and the buyer asked B2Bhaat to decide. Your payment for this parcel is on hold until then. The buyer says: '.$record->escalation_note],
            ReturnRequest::STATUS_REJECTED => ['tone' => 'danger', 'icon' => 'heroicon-o-x-circle', 'heading' => $record->escalation_decided_at ? 'B2Bhaat kept your rejection' : 'You rejected this return',
                'body' => 'The buyer keeps the items.'.(filled($record->admin_arbitration_note) ? ' B2Bhaat\'s note: '.$record->admin_arbitration_note : (filled($record->seller_note) ? ' Your reason: '.$record->seller_note : ''))
                    .($record->canBeEscalated() ? ' The buyer can ask B2Bhaat to review it until '.$record->escalationDeadline()?->timezone(config('app.display_timezone'))->format('d M, g:i A').'; your payment waits until then.' : '')],
            default => ['tone' => 'info', 'icon' => 'heroicon-o-lock-closed', 'heading' => 'This return is closed',
                'body' => $record->seller_note ?: 'Nothing more to do.'],
        };
    }
}
