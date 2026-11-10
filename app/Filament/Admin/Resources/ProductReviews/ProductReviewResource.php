<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductReviews;

use App\Filament\Admin\Resources\ProductReviews\Pages\ListProductReviews;
use App\Models\ProductReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * All buyer reviews. The admin can hide abusive or fake reviews and show them again. Sellers can only reply.
 */
class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review';

    protected static ?string $pluralModelLabel = 'reviews';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.seller.sellerProfile', 'user', 'order']))
            ->defaultSort('created_at', 'desc')
            ->recordAction('read')
            ->columns([
                TextColumn::make('title')
                    ->label('Review')
                    ->weight(FontWeight::Medium)
                    ->placeholder('No title')
                    ->description(fn (ProductReview $record): ?string => filled($record->comment) ? Str::limit($record->comment, 110) : null)
                    ->wrap()
                    ->grow()
                    ->searchable(['title', 'comment']),
                TextColumn::make('rating')
                    ->badge()
                    ->icon(Heroicon::Star)
                    ->color(fn (int $state): string => self::ratingColor($state))
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->limit(36)
                    ->description(fn (ProductReview $record): ?string => $record->product?->seller?->sellerProfile?->store_name)
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Buyer')
                    ->description(fn (ProductReview $record): string => $record->is_verified_purchase ? 'Verified buyer' : 'Did not buy here')
                    ->searchable(),
                TextColumn::make('is_approved')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Shown' : 'Hidden')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Written')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->options([5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']),
                TernaryFilter::make('is_verified_purchase')
                    ->label('Bought the product')
                    ->trueLabel('Verified buyers only')
                    ->falseLabel('Did not buy here'),
                TernaryFilter::make('seller_reply')
                    ->label('Seller reply')
                    ->nullable()
                    ->trueLabel('Seller replied')
                    ->falseLabel('No reply yet'),
            ])
            ->recordActions([
                Action::make('read')
                    ->label('Read')
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->color('gray')
                    ->modalHeading(fn (ProductReview $record): string => $record->title ?: 'Review')
                    ->modalDescription(fn (ProductReview $record): string => ($record->user?->name ?? 'A buyer').' on '.($record->product?->title ?? 'a deleted product'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Grid::make(['default' => 2, 'sm' => 4])->schema([
                            TextEntry::make('rating')->badge()->icon(Heroicon::Star)
                                ->color(fn (ProductReview $record): string => self::ratingColor($record->rating)),
                            TextEntry::make('is_approved')->label('Status')->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Shown in shop' : 'Hidden')
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('is_verified_purchase')->label('Bought it')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Verified buyer' : 'No'),
                            TextEntry::make('created_at')->label('Written')->dateTime('d M Y, g:i A'),
                        ]),
                        TextEntry::make('comment')->label('What the buyer wrote')->placeholder('No comment, rating only')
                            ->extraAttributes(['class' => 'whitespace-pre-line']),
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextEntry::make('store')->label('Seller')
                                ->state(fn (ProductReview $record): ?string => $record->product?->seller?->sellerProfile?->store_name)->placeholder('—'),
                            TextEntry::make('order.order_number')->label('Order')->placeholder('Not linked to an order')->copyable(),
                        ]),
                        TextEntry::make('seller_reply')->label('Seller reply')->placeholder('The seller has not replied')
                            ->belowContent(fn (ProductReview $record): ?string => $record->seller_replied_at?->format('d M Y, g:i A')),
                    ]),
                Action::make('hide')
                    ->label('Hide')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hide this review?')
                    ->modalDescription('It disappears from the product page and stops counting towards the product\'s rating. You can show it again later.')
                    ->modalSubmitActionLabel('Hide review')
                    ->visible(fn (ProductReview $record): bool => $record->is_approved)
                    ->action(function (ProductReview $record): void {
                        $record->update(['is_approved' => false]);

                        Notification::make()->title('Review hidden')->body('The product rating is updated.')->success()->send();
                    }),
                Action::make('show')
                    ->label('Show again')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Show this review again?')
                    ->modalDescription('It goes back on the product page and counts towards the rating.')
                    ->modalSubmitActionLabel('Show review')
                    ->visible(fn (ProductReview $record): bool => ! $record->is_approved)
                    ->action(function (ProductReview $record): void {
                        $record->update(['is_approved' => true]);

                        Notification::make()->title('Review shown again')->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedStar)
            ->emptyStateHeading('No reviews here')
            ->emptyStateDescription('Buyers can review a product after their order is delivered.');
    }

    public static function ratingColor(int $rating): string
    {
        return match (true) {
            $rating <= 2 => 'danger',
            $rating === 3 => 'warning',
            default => 'success',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviews::route('/'),
        ];
    }
}
