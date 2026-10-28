<?php

namespace App\Filament\Seller\Resources\ProductReviews\Pages;

use App\Filament\Seller\Resources\ProductReviews\ProductReviewResource;
use Filament\Resources\Pages\EditRecord;

class EditProductReview extends EditRecord
{
    protected static string $resource = ProductReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['seller_reply']) && empty($this->record->seller_replied_at)) {
            $data['seller_replied_at'] = now();
        }

        return $data;
    }
}
