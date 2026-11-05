<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductReviews;

use App\Filament\Admin\Resources\ProductReviews\Pages\ListProductReviews;
use App\Models\ProductReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * All buyer reviews. The admin can hide abusive or fake reviews and show them again. Sellers can only reply.
 */
class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Reviews';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.title')->label('Product')->searchable()->limit(40),
                TextColumn::make('user.name')->label('Buyer')->searchable(),
                TextColumn::make('rating')->formatStateUsing(fn (int $state): string => $state.' / 5')->sortable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('comment')->wrap()->limit(80),
                IconColumn::make('is_verified_purchase')->label('Bought it')->boolean(),
                IconColumn::make('is_approved')->label('Shown')->boolean(),
                TextColumn::make('created_at')->label('Written')->date('M d, Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_approved')->label('Shown in shop'),
                SelectFilter::make('rating')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The review disappears from the product page and from its rating.')
                    ->visible(fn (ProductReview $record): bool => $record->is_approved)
                    ->action(function (ProductReview $record): void {
                        $record->update(['is_approved' => false]);

                        Notification::make()->title('Review hidden')->success()->send();
                    }),
                Action::make('show')
                    ->label('Show again')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (ProductReview $record): bool => ! $record->is_approved)
                    ->action(function (ProductReview $record): void {
                        $record->update(['is_approved' => true]);

                        Notification::make()->title('Review shown again')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviews::route('/'),
        ];
    }
}
