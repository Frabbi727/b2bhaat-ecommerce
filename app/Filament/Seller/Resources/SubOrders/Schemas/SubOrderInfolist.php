<?php

namespace App\Filament\Seller\Resources\SubOrders\Schemas;

use App\Filament\Admin\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Admin\Support\StatusColors;
use App\Models\OrderItem;
use App\Models\PaymentStatus;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Formatting\Money;
use App\Services\Settings\StoreConfigurationService;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;

/**
 * The page a seller reads to understand one parcel: where it stands and what to do next, what to pack,
 * who it goes to, how the buyer pays and what the seller earns. Every value is read-only.
 */
class SubOrderInfolist
{
    /** The delivery journey shown as steps; a status not listed here (cancelled, returned) hides the steps. */
    public const STEPS = [
        'received' => 'Received',
        'processing' => 'Packed',
        'shipped' => 'Shipped',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewEntry::make('status_overview')
                    ->hiddenLabel()
                    ->view('filament.seller.sub-orders.status-overview')
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            self::itemsSection(),
                            self::moneySection(),
                            self::activitySection(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            self::deliverToSection(),
                            self::paymentSection(),
                            self::shippingSection(),
                            self::referenceSection(),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    protected static function itemsSection(): Section
    {
        return Section::make(fn (SubOrder $record): string => in_array($record->fulfillmentStatus?->slug, ['unfulfilled', 'processing'], true) ? 'Items to pack' : 'Items in this parcel')
            ->description(function (SubOrder $record): string {
                $lines = $record->items->count();
                $pieces = (int) $record->items->sum('quantity');

                return $lines.' '.str('product')->plural($lines).' · '.number_format($pieces).' '.str('piece')->plural($pieces).' in total';
            })
            ->icon('heroicon-o-archive-box')
            ->schema([
                RepeatableEntry::make('items')
                    ->hiddenLabel()
                    ->contained(false)
                    ->table([
                        TableColumn::make('Product'),
                        TableColumn::make('Qty')->width('110px')->alignment('end'),
                        TableColumn::make('Unit price')->width('130px')->alignment('end'),
                        TableColumn::make('Total')->width('130px')->alignment('end'),
                    ])
                    ->schema([
                        TextEntry::make('product.title')
                            ->label('Product')
                            ->state(fn (OrderItem $record): string => $record->product?->title ?? 'Removed product')
                            ->weight('medium')
                            ->belowContent(fn (OrderItem $record): ?string => self::itemDetails($record)),
                        TextEntry::make('quantity')
                            ->label('Qty')
                            ->formatStateUsing(fn (OrderItem $record): string => number_format($record->quantity).($record->product?->unit?->symbol ? ' '.$record->product->unit->symbol : ''))
                            ->weight('bold')
                            ->alignEnd(),
                        TextEntry::make('unit_price')
                            ->label('Unit price')
                            ->formatStateUsing(fn ($state): string => Money::taka($state))
                            ->belowContent(fn (OrderItem $record): ?string => (float) $record->original_unit_price > (float) $record->unit_price
                                ? 'was '.Money::taka($record->original_unit_price)
                                : null)
                            ->alignEnd(),
                        TextEntry::make('total_price')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => Money::taka($state))
                            ->weight('medium')
                            ->alignEnd(),
                    ]),
            ]);
    }

    protected static function moneySection(): Section
    {
        return Section::make('Your earnings')
            ->icon('heroicon-o-banknotes')
            ->columns(['default' => 2, 'md' => 4])
            ->schema([
                TextEntry::make('subtotal')
                    ->label('Goods sold')
                    ->formatStateUsing(fn ($state): string => Money::taka($state)),
                TextEntry::make('commission_amount')
                    ->label('Platform fee')
                    ->formatStateUsing(fn ($state): string => Money::taka(-(float) $state))
                    ->color('danger'),
                TextEntry::make('shipping_amount')
                    ->label('Delivery fee (yours)')
                    ->helperText('You delivered it, so you keep the fee.')
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->visible(fn (SubOrder $record): bool => $record->is_self_delivered && (float) $record->shipping_amount > 0),
                TextEntry::make('payout')
                    ->label('You receive')
                    ->state(fn (SubOrder $record): float => $record->sellerPayableAmount())
                    ->formatStateUsing(fn ($state, SubOrder $record): string => self::isCancelled($record) ? 'Nothing' : Money::taka($state))
                    ->helperText(fn (SubOrder $record): ?string => self::isCancelled($record) ? 'Cancelled orders are not paid out.' : null)
                    ->size(TextSize::Large)
                    ->weight('bold')
                    ->color(fn (SubOrder $record): string => self::isCancelled($record) ? 'gray' : 'success'),
            ]);
    }

    protected static function activitySection(): Section
    {
        return Section::make('Activity')
            ->icon('heroicon-o-clock')
            ->collapsible()
            ->schema([
                ViewEntry::make('activity')
                    ->hiddenLabel()
                    ->view('filament.seller.sub-orders.activity'),
            ]);
    }

    protected static function deliverToSection(): Section
    {
        return Section::make('Deliver to')
            ->icon('heroicon-o-map-pin')
            ->schema([
                TextEntry::make('recipient')
                    ->hiddenLabel()
                    ->state(fn (SubOrder $record): string => $record->order?->shipping_address['recipient_name'] ?? $record->order?->customer?->name ?? 'Guest')
                    ->size(TextSize::Large)
                    ->weight('bold'),
                TextEntry::make('phone')
                    ->label('Phone')
                    ->state(fn (SubOrder $record): ?string => $record->order?->shipping_address['recipient_phone'] ?? $record->order?->customer?->phone)
                    ->icon('heroicon-m-phone')
                    ->url(fn (?string $state): ?string => $state ? 'tel:'.$state : null)
                    ->copyable()
                    ->placeholder('No phone given'),
                TextEntry::make('address')
                    ->label('Address')
                    ->state(fn (SubOrder $record): string => OrderInfolist::formatAddress($record->order?->shipping_address))
                    ->copyable()
                    ->placeholder('No address given'),
                TextEntry::make('zone')
                    ->label('Delivery zone')
                    ->state(fn (SubOrder $record): ?string => isset($record->order?->shipping_address['delivery_zone'])
                        ? str($record->order->shipping_address['delivery_zone'])->headline()->toString()
                        : null)
                    ->visible(fn (?string $state): bool => filled($state)),
            ]);
    }

    protected static function paymentSection(): Section
    {
        return Section::make('Payment')
            ->icon('heroicon-o-credit-card')
            ->columns(2)
            ->schema([
                TextEntry::make('order.paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextEntry::make('order.paymentStatus.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SubOrder $record): string => StatusColors::payment($record->order?->paymentStatus?->slug))
                    ->placeholder('—'),
                TextEntry::make('collect')
                    ->label(fn (SubOrder $record): string => $record->fulfillmentStatus?->slug === 'delivered' ? 'Cash collected on delivery' : 'Cash to collect at the door')
                    ->state(fn (SubOrder $record): float => $record->codCollectAmount())
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->size(TextSize::Large)
                    ->weight('bold')
                    ->color('warning')
                    ->columnSpanFull()
                    ->visible(fn (SubOrder $record): bool => $record->order?->paymentMethod?->slug === 'cod' && ! in_array($record->fulfillmentStatus?->slug, ['cancelled', 'returned'], true)),
                TextEntry::make('prepaid')
                    ->hiddenLabel()
                    ->state('Already paid online, so collect nothing at the door.')
                    ->icon('heroicon-m-check-circle')
                    ->iconColor('success')
                    ->columnSpanFull()
                    ->visible(fn (SubOrder $record): bool => $record->order?->paymentMethod?->slug !== 'cod'
                        && in_array($record->order?->paymentStatus?->slug, PaymentStatus::MONEY_RECEIVED, true)),
            ]);
    }

    protected static function shippingSection(): Section
    {
        return Section::make('Shipping')
            ->icon('heroicon-o-truck')
            ->schema([
                TextEntry::make('delivery_mode')
                    ->label('Delivered by')
                    ->state(fn (SubOrder $record): string => $record->is_self_delivered
                        ? 'You (own delivery)'
                        : ($record->courier_name ?: 'Not booked yet')),
                TextEntry::make('tracking_number')
                    ->label('Tracking number')
                    ->fontFamily('mono')
                    ->copyable()
                    ->url(fn (SubOrder $record): ?string => $record->tracking_url, shouldOpenInNewTab: true)
                    ->visible(fn (SubOrder $record): bool => filled($record->tracking_number)),
                TextEntry::make('handover_confirmed_at')
                    ->label('Handover confirmed')
                    ->dateTime('d M Y, g:i A')
                    ->visible(fn (SubOrder $record): bool => $record->handover_confirmed_at !== null),
            ]);
    }

    protected static function referenceSection(): Section
    {
        return Section::make('Order details')
            ->icon('heroicon-o-document-text')
            ->columns(2)
            ->schema([
                TextEntry::make('sub_order_number')
                    ->label('Package no.')
                    ->fontFamily('mono')
                    ->copyable()
                    ->columnSpanFull(),
                TextEntry::make('order.order_number')
                    ->label('Customer order')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Received')
                    ->dateTime('d M Y, g:i A'),
                TextEntry::make('channel')
                    ->label('Sold as')
                    ->state(fn (SubOrder $record): array => $record->items->pluck('salesChannel.name')->filter()->unique()->values()->all())
                    ->badge()
                    ->color(fn (string $state): string => str($state)->lower()->contains('wholesale') ? 'primary' : 'gray')
                    ->placeholder('—'),
                TextEntry::make('replaces.sub_order_number')
                    ->label('Free replacement for')
                    ->fontFamily('mono')
                    ->columnSpanFull()
                    ->visible(fn (SubOrder $record): bool => $record->isReplacement()),
            ]);
    }

    /**
     * Option values and SKU under a product name, e.g. "Red · XL · SKU TS-RED-XL".
     */
    protected static function itemDetails(OrderItem $item): ?string
    {
        $parts = $item->variation?->attributeValues->pluck('value')->filter()->all() ?? [];

        if ($sku = $item->variation?->sku ?? $item->product?->sku) {
            $parts[] = 'SKU '.$sku;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** Dates shown in the panel's display timezone, the same one Filament's own date entries use. */
    public static function localTime(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(FilamentTimezone::get());
    }

    protected static function isCancelled(SubOrder $record): bool
    {
        return $record->fulfillmentStatus?->slug === 'cancelled';
    }

    /**
     * Where the parcel stands and the one thing the seller should do next.
     *
     * @return array{tone: string, icon: string, heading: string, body: string, status: string, statusColor: string, steps: list<array{label: string, state: string, at: ?string}>}
     */
    public static function statusSummary(SubOrder $record): array
    {
        $slug = $record->fulfillmentStatus?->slug;
        $payable = Money::taka($record->sellerPayableAmount());
        $isCod = $record->order?->paymentMethod?->slug === 'cod';
        $collect = $isCod ? ' Collect '.Money::taka($record->codCollectAmount()).' in cash on delivery.' : ' The buyer has already paid, so collect nothing at the door.';
        $pieces = number_format((int) $record->items->sum('quantity'));

        [$tone, $icon, $heading, $body] = match (true) {
            $slug === 'cancelled' => ['danger', 'heroicon-o-x-circle', 'This order was cancelled',
                collect([
                    $record->cancelled_by ? 'Cancelled by the '.str($record->cancelled_by)->replace('_', ' ').($record->cancelled_at ? ' on '.self::localTime($record->cancelled_at)->format('d M Y') : '').'.' : null,
                    $record->cancel_reason ? 'Reason: '.$record->cancel_reason.'.' : null,
                    'Nothing to ship and no payout. The stock went back on sale.',
                ])->filter()->implode(' ')],
            $slug === 'returned' => ['danger', 'heroicon-o-arrow-uturn-left', 'The parcel came back',
                'The customer refused it or the courier could not deliver it. Check the goods when they reach you. No payout for this order.'],
            $slug === 'delivered' => self::deliveredSummary($record, $payable),
            ! $record->isClearedForDispatch() => ['warning', 'heroicon-o-pause-circle', 'Wait: payment not confirmed yet',
                'B2Bhaat is still checking the buyer\'s payment. Do not pack or ship until the payment shows as Paid. You will be notified.'],
            $slug === 'unfulfilled' => ['warning', 'heroicon-o-archive-box', 'New order: pack it',
                "Pack the {$pieces} pieces listed below, then press Mark as Packed. The buyer can cancel online until you do."],
            $slug === 'processing' => ['primary', 'heroicon-o-truck', 'Packed: send it out',
                'Print the shipping label, then hand the parcel to a courier with Dispatch Courier, or press Deliver Myself if your own person takes it.'.$collect],
            $record->is_self_delivered => ['info', 'heroicon-o-key', 'On the way with your delivery person',
                'After the customer receives the parcel'.($isCod ? ' and pays' : '').', ask for their 6-digit code and enter it with Confirm Handover Code. That marks it delivered.'.($isCod ? ' Collect '.Money::taka($record->codCollectAmount()).' in cash.' : '')],
            default => ['info', 'heroicon-o-truck', 'With '.($record->courier_name ?: 'the courier'),
                'Nothing to do now. Delivery is confirmed automatically when the courier reports it, and your '.$payable.' payout starts from then.'],
        };

        return [
            'tone' => $tone,
            'icon' => $icon,
            'heading' => $heading,
            'body' => $body,
            'status' => $record->fulfillmentStatus?->name ?? 'Unknown',
            'statusColor' => StatusColors::fulfillment($slug),
            'steps' => in_array($slug, ['cancelled', 'returned'], true) ? [] : self::steps($record, $slug),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected static function deliveredSummary(SubOrder $record, string $payable): array
    {
        $released = WalletTransaction::where('sub_order_id', $record->id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
            ->exists();

        if ($released) {
            return ['success', 'heroicon-o-check-badge', 'Delivered and paid', "{$payable} has been added to your available balance."];
        }

        $hours = app(StoreConfigurationService::class)->getEscrowHoldingPeriodHours();
        $releaseAt = $record->deliveredAt()?->copy()->addHours($hours);

        return ['success', 'heroicon-o-check-circle', 'Delivered',
            "{$payable} is on hold for {$hours} hours in case the buyer reports a problem".($releaseAt ? ', and moves to your balance around '.self::localTime($releaseAt)->format('d M, g:i A') : '').'.'];
    }

    /**
     * @return list<array{label: string, state: string, at: ?string}>
     */
    protected static function steps(SubOrder $record, ?string $slug): array
    {
        // The last step already reached; the one after it is what happens next
        $position = match ($slug) {
            'processing' => 1,
            'shipped', 'in_transit' => 2,
            'out_for_delivery' => 3,
            'delivered' => 4,
            default => 0,
        };

        $loggedAt = $record->trackingLogs
            ->filter(fn ($log): bool => $log->fulfillmentStatus !== null)
            ->groupBy(fn ($log): string => $log->fulfillmentStatus->slug === 'in_transit' ? 'shipped' : $log->fulfillmentStatus->slug)
            ->map(fn ($logs) => $logs->min('logged_at'));

        $steps = [];
        foreach (array_keys(self::STEPS) as $index => $key) {
            $at = $key === 'received' ? $record->created_at : $loggedAt->get($key);

            $steps[] = [
                'label' => self::STEPS[$key],
                'state' => match (true) {
                    $index <= $position => 'done',
                    $index === $position + 1 => 'current',
                    default => 'upcoming',
                },
                'at' => $index <= $position && $at ? self::localTime($at)->format('d M, g:i A') : null,
            ];
        }

        return $steps;
    }
}
