<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Orders come only from checkout; there is no admin "New order" (roadmap 5.6, WA-ADM-06).
 */
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
