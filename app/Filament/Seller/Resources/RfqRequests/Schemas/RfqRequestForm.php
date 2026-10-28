<?php

namespace App\Filament\Seller\Resources\RfqRequests\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RfqRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'title')
                    ->required(),
                Select::make('seller_id')
                    ->relationship('seller', 'name')
                    ->required(),
                Select::make('buyer_id')
                    ->relationship('buyer', 'name'),
                TextInput::make('buyer_name')
                    ->required(),
                TextInput::make('buyer_email')
                    ->email()
                    ->required(),
                TextInput::make('buyer_phone')
                    ->tel()
                    ->required(),
                TextInput::make('company_name'),
                TextInput::make('target_quantity')
                    ->required()
                    ->numeric(),
                TextInput::make('target_unit_price')
                    ->numeric()
                    ->prefix('৳'),
                TextInput::make('destination_district')
                    ->required()
                    ->default('Dhaka'),
                Textarea::make('specifications')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('seller_quoted_price')
                    ->numeric()
                    ->prefix('৳'),
                Textarea::make('seller_notes')
                    ->columnSpanFull(),
            ]);
    }
}
