<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListSubOrders extends ListRecords
{
    protected static string $resource = SubOrderResource::class;
}
