<?php

namespace App\Filament\Seller\Resources\SellerWallets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SellerWalletForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('seller_id')
                    ->relationship('seller', 'name')
                    ->required(),
                TextInput::make('available_balance')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('escrow_held_balance')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
