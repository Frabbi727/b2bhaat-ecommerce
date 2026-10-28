<?php

namespace App\Filament\Seller\Resources\SubOrders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('parent_order_id')
                    ->required()
                    ->numeric(),
                Select::make('seller_id')
                    ->relationship('seller', 'name')
                    ->required(),
                TextInput::make('sub_order_number')
                    ->required(),
                Select::make('fulfillment_status_id')
                    ->relationship('fulfillmentStatus', 'name')
                    ->required(),
                TextInput::make('courier_name'),
                TextInput::make('tracking_number'),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric(),
                TextInput::make('commission_amount')
                    ->required()
                    ->numeric(),
            ]);
    }
}
