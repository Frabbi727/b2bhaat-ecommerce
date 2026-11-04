<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Sellers can read a sub-order but never edit its money, ownership or parent (PRA-005).
 */
class ViewSubOrder extends ViewRecord
{
    protected static string $resource = SubOrderResource::class;
}
