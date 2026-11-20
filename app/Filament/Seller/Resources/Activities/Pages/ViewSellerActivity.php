<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Activities\Pages;

use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSellerActivity extends ViewRecord
{
    protected static string $resource = SellerActivityResource::class;
}
