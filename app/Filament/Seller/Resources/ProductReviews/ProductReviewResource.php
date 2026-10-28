<?php

namespace App\Filament\Seller\Resources\ProductReviews;

use App\Filament\Seller\Resources\ProductReviews\Pages\EditProductReview;
use App\Filament\Seller\Resources\ProductReviews\Pages\ListProductReviews;
use App\Filament\Seller\Resources\ProductReviews\Schemas\ProductReviewForm;
use App\Filament\Seller\Resources\ProductReviews\Tables\ProductReviewsTable;
use App\Models\ProductReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Buyer Reviews';

    protected static ?string $modelLabel = 'Review';

    protected static ?string $pluralModelLabel = 'Buyer Reviews';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('product', fn (Builder $q) => $q->where('seller_id', auth()->id()));
    }

    public static function getNavigationBadge(): ?string
    {
        $pendingReplies = static::getModel()::whereHas('product', fn (Builder $q) => $q->where('seller_id', auth()->id()))
            ->whereNull('seller_reply')
            ->count();

        return $pendingReplies > 0 ? (string) $pendingReplies : null;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductReviewForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductReviewsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductReviews::route('/'),
            'edit' => EditProductReview::route('/{record}/edit'),
        ];
    }
}
