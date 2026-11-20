<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductReviews\Pages;

use App\Filament\Admin\Resources\ProductReviews\ProductReviewResource;
use App\Models\ProductReview;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListProductReviews extends ListRecords
{
    protected static string $resource = ProductReviewResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Reviews appear in the shop as soon as buyers post them. Read the low ratings first and hide only abusive, fake or off-topic ones. Sellers can reply, but they cannot hide a review.';
    }

    public function getTabs(): array
    {
        $lowAndShown = ProductReview::where('is_approved', true)->where('rating', '<=', 2)->count();

        return [
            'all' => Tab::make('All reviews'),
            'low' => Tab::make('1–2 stars')
                ->badge($lowAndShown ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rating', '<=', 2)),
            'shown' => Tab::make('Shown')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)),
            'hidden' => Tab::make('Hidden')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)),
        ];
    }
}
