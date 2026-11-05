<?php

namespace App\Filament\Seller\Resources\RfqRequests\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Sellers only answer an RFQ: the buyer's request is read-only and ownership fields are not on the form (PRA-012).
 */
class RfqRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Buyer Request')
                    ->columns(2)
                    ->schema([
                        TextInput::make('buyer_name')->disabled()->dehydrated(false),
                        TextInput::make('company_name')->disabled()->dehydrated(false),
                        TextInput::make('buyer_email')->disabled()->dehydrated(false),
                        TextInput::make('buyer_phone')->disabled()->dehydrated(false),
                        TextInput::make('target_quantity')->disabled()->dehydrated(false),
                        TextInput::make('target_unit_price')->prefix('৳')->disabled()->dehydrated(false),
                        TextInput::make('destination_district')->disabled()->dehydrated(false),
                        Textarea::make('specifications')->disabled()->dehydrated(false)->columnSpanFull(),
                    ]),

                Section::make('Your Quotation')
                    ->columns(2)
                    ->schema([
                        TextInput::make('seller_quoted_price')
                            ->label('Quoted Price per Unit')
                            ->numeric()
                            ->minValue(1)
                            ->prefix('৳')
                            ->required(fn (callable $get): bool => $get('status') === 'quoted'),
                        Select::make('status')
                            ->options([
                                'quoted' => 'Quoted',
                                'declined' => 'Declined',
                            ])
                            ->in(['quoted', 'declined'])
                            ->required(),
                        Textarea::make('seller_notes')
                            ->label('Quotation Terms & Delivery Timeline')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
