<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ReturnRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subOrder.sub_order_number')
                    ->label('Consignment #')
                    ->disabled(),
                TextInput::make('customer.name')
                    ->label('Customer')
                    ->disabled(),
                TextInput::make('reason')
                    ->label('Dispute Reason')
                    ->disabled(),
                TextInput::make('requested_action')
                    ->label('Desired Resolution')
                    ->disabled(),
                TextInput::make('status')
                    ->label('Status')
                    ->disabled(),
                Textarea::make('description')
                    ->label('Buyer Explanation')
                    ->disabled()
                    ->columnSpanFull(),
                Textarea::make('seller_note')
                    ->label('Seller Response Note')
                    ->columnSpanFull(),
            ]);
    }
}
