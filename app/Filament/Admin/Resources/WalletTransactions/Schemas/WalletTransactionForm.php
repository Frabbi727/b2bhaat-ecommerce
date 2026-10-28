<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WalletTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('seller_wallet_id')
                    ->required()
                    ->numeric(),
                Select::make('sub_order_id')
                    ->relationship('subOrder', 'id'),
                TextInput::make('transaction_type')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('running_balance')
                    ->required()
                    ->numeric(),
                Textarea::make('reference_note')
                    ->columnSpanFull(),
            ]);
    }
}
