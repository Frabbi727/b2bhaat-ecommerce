<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SellerProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('store_name')
                    ->required(),
                TextInput::make('store_slug')
                    ->required(),
                TextInput::make('trade_license_number'),
                TextInput::make('tax_id_number'),
                TextInput::make('commission_rate_percentage')
                    ->required()
                    ->numeric()
                    ->default(10),
                Toggle::make('is_approved')
                    ->required(),
            ]);
    }
}
