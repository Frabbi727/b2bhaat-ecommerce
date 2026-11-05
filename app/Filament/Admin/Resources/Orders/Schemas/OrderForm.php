<?php

namespace App\Filament\Admin\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->disabled()
                    ->dehydrated(false)
                    ->required(),
                Select::make('customer_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->relationship('customer', 'name')
                    ->required(),
                Select::make('order_status_id')
                    ->relationship('orderStatus', 'name')
                    ->required(),
                Select::make('payment_status_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->relationship('paymentStatus', 'name')
                    ->required(),
                Select::make('payment_method_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->relationship('paymentMethod', 'name')
                    ->required(),
                TextInput::make('shipping_address')
                    ->disabled()
                    ->dehydrated(false)
                    ->required(),
                TextInput::make('total_amount')
                    ->disabled()
                    ->dehydrated(false)
                    ->required()
                    ->numeric(),
                TextInput::make('tax_amount')
                    ->disabled()
                    ->dehydrated(false)
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('shipping_amount')
                    ->disabled()
                    ->dehydrated(false)
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
