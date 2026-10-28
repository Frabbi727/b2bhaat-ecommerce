<?php

namespace App\Filament\Seller\Resources\ProductReviews\Pages;

use App\Filament\Seller\Resources\ProductReviews\ProductReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListProductReviews extends ListRecords
{
    protected static string $resource = ProductReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
