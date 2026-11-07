<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Activities\Pages;

use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListSellerActivities extends ListRecords
{
    protected static string $resource = SellerActivityResource::class;
}
