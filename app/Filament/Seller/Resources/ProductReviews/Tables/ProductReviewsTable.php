<?php

namespace App\Filament\Seller\Resources\ProductReviews\Tables;

use App\Models\ProductReview;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Date')
                    ->dateTime('d M Y')
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable()
                    ->limit(25),
                TextColumn::make('user.name')
                    ->visibleFrom('md')
                    ->label('Buyer')
                    ->searchable(),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn ($state) => "{$state} ★")
                    ->badge()
                    ->color(fn (ProductReview $record): array => match ($record->rating) {
                        5, 4 => Color::Amber,
                        3 => Color::Blue,
                        default => Color::Rose,
                    })
                    ->sortable(),
                IconColumn::make('is_verified_purchase')
                    ->visibleFrom('md')
                    ->label('Verified')
                    ->boolean(),
                TextColumn::make('title')
                    ->visibleFrom('md')
                    ->label('Headline')
                    ->limit(20)
                    ->placeholder('-'),
                TextColumn::make('comment')
                    ->label('Review')
                    ->limit(35),
                TextColumn::make('seller_reply')
                    ->visibleFrom('md')
                    ->label('Factory Reply')
                    ->limit(25)
                    ->placeholder('Pending Reply')
                    ->color(fn ($state) => $state ? Color::Emerald : Color::Zinc),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('reply')
                    ->label('Factory Reply')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->color('primary')
                    ->form([
                        Textarea::make('seller_reply')
                            ->label('Official Factory Response')
                            ->rows(4)
                            ->required()
                            ->default(fn (ProductReview $record) => $record->seller_reply)
                            ->placeholder('Address feedback or thank the buyer...'),
                    ])
                    ->action(function (ProductReview $record, array $data) {
                        $record->update([
                            'seller_reply' => $data['seller_reply'],
                            'seller_replied_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Factory Reply Published')
                            ->body('Your official response has been displayed on the public storefront.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ]);
    }
}
