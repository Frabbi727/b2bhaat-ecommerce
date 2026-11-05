<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductModeration\Pages;

use App\Filament\Admin\Resources\ProductModeration\ProductModerationResource;
use Filament\Resources\Pages\ListRecords;

class ListModeratedProducts extends ListRecords
{
    protected static string $resource = ProductModerationResource::class;
}
