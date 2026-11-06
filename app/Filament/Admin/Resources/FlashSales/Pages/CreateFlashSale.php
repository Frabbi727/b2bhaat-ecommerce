<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Pages;

use App\Enums\FlashSaleStatus;
use App\Filament\Admin\Resources\FlashSales\FlashSaleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateFlashSale extends CreateRecord
{
    protected static string $resource = FlashSaleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['slug']) && ! empty($data['name'])) {
            $data['slug'] = Str::slug((string) $data['name']);
        }

        $data['status'] = FlashSaleStatus::Draft;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
