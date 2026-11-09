<?php

namespace App\Filament\Admin\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything here is locked. The order status follows its parcels (ORD-1); money, payment and customer details change
 * only through payment verification, refunds and the sellers' fulfilment. There is no edit page for orders.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order status')
                    ->description('Set automatically from the parcels: cancelled when all are cancelled or returned, completed when all are finished and at least one was delivered.')
                    ->schema([
                        Select::make('order_status_id')
                            ->label('Status')
                            ->relationship('orderStatus', 'name')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('Order details')
                    ->description('Locked. Use “Verify payment” or a refund to change payment or money.')
                    ->collapsible()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('order_number')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('payment_status_id')
                            ->label('Payment status')
                            ->relationship('paymentStatus', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('payment_method_id')
                            ->label('Payment method')
                            ->relationship('paymentMethod', 'name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('shipping_address')
                            ->label('Delivery address')
                            ->formatStateUsing(fn (mixed $state): ?string => is_array($state)
                                ? collect([$state['recipient_name'] ?? null, $state['recipient_phone'] ?? null, OrderInfolist::formatAddress($state)])->filter()->implode(' · ')
                                : $state)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        TextInput::make('total_amount')
                            ->label('Total')
                            ->prefix('৳')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('shipping_amount')
                            ->label('Delivery')
                            ->prefix('৳')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('tax_amount')
                            ->label('VAT')
                            ->prefix('৳')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
