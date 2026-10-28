<?php

namespace App\Filament\Seller\Resources\ProductReviews\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('product.title')
                    ->label('Product Title')
                    ->disabled(),
                TextInput::make('user.name')
                    ->label('Buyer Name')
                    ->disabled(),
                TextInput::make('rating')
                    ->label('Rating (Stars)')
                    ->suffix('★')
                    ->disabled(),
                Toggle::make('is_verified_purchase')
                    ->label('Verified Purchase')
                    ->disabled(),
                TextInput::make('title')
                    ->label('Review Headline')
                    ->disabled()
                    ->columnSpanFull(),
                Textarea::make('comment')
                    ->label('Buyer Review')
                    ->disabled()
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('seller_reply')
                    ->label('Official Factory Reply')
                    ->placeholder('Address the customer review directly...')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
