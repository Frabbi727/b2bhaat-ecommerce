<?php

namespace App\Filament\Admin\Resources\Orders\Schemas;

use App\Filament\Admin\Support\StatusColors;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SubOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The order page an admin reads when a customer calls: who, where, what was paid, and where each seller's
 * parcel is. Every value is read-only; money and payment state only change through their own actions.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            self::summarySection(),
                            self::consignmentsSection(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            self::customerSection(),
                            self::paymentSection(),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    protected static function summarySection(): Section
    {
        return Section::make('Summary')
            ->columns(['default' => 2, 'sm' => 4])
            ->schema([
                TextEntry::make('orderStatus.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Order $record): string => StatusColors::order($record->orderStatus?->slug)),
                TextEntry::make('created_at')
                    ->label('Placed')
                    ->dateTime('d M Y, g:i A'),
                TextEntry::make('sub_orders_count')
                    ->label('Sellers')
                    ->state(fn (Order $record): int => $record->subOrders->count()),
                TextEntry::make('total_amount')
                    ->label('Order total')
                    ->money('BDT')
                    ->weight('bold'),
            ]);
    }

    protected static function consignmentsSection(): Section
    {
        return Section::make('Items by seller')
            ->description('Each seller packs and ships their own parcel.')
            ->schema([
                RepeatableEntry::make('subOrders')
                    ->hiddenLabel()
                    ->schema([
                        Grid::make(['default' => 2, 'md' => 4])->schema([
                            TextEntry::make('seller.sellerProfile.store_name')
                                ->label('Seller')
                                ->weight('medium')
                                ->placeholder(fn (SubOrder $record): ?string => $record->seller?->name),
                            TextEntry::make('fulfillmentStatus.name')
                                ->label('Delivery')
                                ->badge()
                                ->color(fn (SubOrder $record): string => StatusColors::fulfillment($record->fulfillmentStatus?->slug)),
                            TextEntry::make('courier')
                                ->label('Courier')
                                ->state(fn (SubOrder $record): string => $record->is_self_delivered ? 'Seller delivers' : trim(($record->courier_name ?? '').' '.($record->tracking_number ?? '')))
                                ->placeholder('Not booked yet')
                                ->url(fn (SubOrder $record): ?string => $record->tracking_url, shouldOpenInNewTab: true),
                            TextEntry::make('sub_order_number')
                                ->label('Parcel no.')
                                ->copyable(),
                        ]),
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Qty')->width('70px'),
                                TableColumn::make('Unit price')->width('120px'),
                                TableColumn::make('Total')->width('120px'),
                            ])
                            ->schema([
                                TextEntry::make('product.title')
                                    ->state(fn (OrderItem $record): string => $record->product?->title ?? 'Removed product')
                                    ->belowContent(fn (OrderItem $record): ?string => $record->variation?->sku),
                                TextEntry::make('quantity')->numeric(),
                                TextEntry::make('unit_price')->money('BDT'),
                                TextEntry::make('total_price')->money('BDT')->weight('medium'),
                            ]),
                        Grid::make(['default' => 2, 'md' => 4])->schema([
                            TextEntry::make('subtotal')->label('Goods')->money('BDT'),
                            TextEntry::make('shipping_amount')->label('Delivery fee')->money('BDT'),
                            TextEntry::make('commission_amount')->label('Marketplace commission')->money('BDT'),
                            TextEntry::make('net_earnings')->label('Seller receives')->money('BDT')->weight('medium'),
                        ]),
                    ]),
            ]);
    }

    protected static function customerSection(): Section
    {
        return Section::make('Customer & delivery')
            ->schema([
                TextEntry::make('recipient')
                    ->label('Deliver to')
                    ->state(fn (Order $record): string => $record->shipping_address['recipient_name'] ?? $record->customer?->name ?? '—')
                    ->weight('medium'),
                TextEntry::make('phone')
                    ->label('Phone')
                    ->state(fn (Order $record): ?string => $record->shipping_address['recipient_phone'] ?? $record->customer?->phone)
                    ->url(fn (?string $state): ?string => $state ? 'tel:'.$state : null)
                    ->copyable()
                    ->placeholder('—'),
                TextEntry::make('address')
                    ->label('Address')
                    ->state(fn (Order $record): string => self::formatAddress($record->shipping_address))
                    ->placeholder('—'),
                TextEntry::make('zone')
                    ->label('Delivery zone')
                    ->state(fn (Order $record): ?string => isset($record->shipping_address['delivery_zone']) ? str($record->shipping_address['delivery_zone'])->headline()->toString() : null)
                    ->placeholder('—'),
                TextEntry::make('customer.email')
                    ->label('Account')
                    ->state(fn (Order $record): ?string => $record->customer ? $record->customer->name.' · '.$record->customer->email : null)
                    ->placeholder('Guest'),
            ]);
    }

    protected static function paymentSection(): Section
    {
        return Section::make('Payment')
            ->schema([
                TextEntry::make('paymentStatus.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Order $record): string => StatusColors::payment($record->paymentStatus?->slug)),
                TextEntry::make('paymentMethod.name')
                    ->label('Method'),
                TextEntry::make('mfs')
                    ->label('Mobile payment')
                    ->state(fn (Order $record): ?string => isset($record->shipping_address['mfs_transaction_id'])
                        ? ($record->shipping_address['mfs_gateway'] ?? 'MFS').' TrxID '.$record->shipping_address['mfs_transaction_id'].' from '.($record->shipping_address['mfs_sender_number'] ?? '—')
                        : null)
                    ->copyable()
                    ->visible(fn (Order $record): bool => isset($record->shipping_address['mfs_transaction_id'])),
                Grid::make(2)->schema([
                    TextEntry::make('goods')
                        ->label('Goods')
                        ->state(fn (Order $record): float => (float) $record->subOrders->sum('subtotal'))
                        ->money('BDT'),
                    TextEntry::make('shipping_amount')->label('Delivery')->money('BDT'),
                    TextEntry::make('discount_amount')
                        ->label(fn (Order $record): string => $record->coupon_code ? "Coupon {$record->coupon_code}" : 'Discount')
                        ->money('BDT')
                        ->visible(fn (Order $record): bool => (float) $record->discount_amount > 0),
                    TextEntry::make('tax_amount')
                        ->label('VAT')
                        ->money('BDT')
                        ->visible(fn (Order $record): bool => (float) $record->tax_amount > 0),
                    TextEntry::make('total_amount')->label('Total')->money('BDT')->weight('bold'),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    public static function formatAddress(?array $address): string
    {
        return collect([$address['street_address'] ?? null, $address['city'] ?? null, $address['postal_code'] ?? null])
            ->filter()
            ->implode(', ');
    }
}
