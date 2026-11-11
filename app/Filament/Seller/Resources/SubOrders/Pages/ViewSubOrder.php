<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Filament\Seller\Resources\SubOrders\Tables\SubOrdersTable;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Sellers can read a sub-order but never edit its money, ownership or parent (PRA-005). The delivery steps (dispatch,
 * handover code) are buttons here as well as in the orders list.
 */
class ViewSubOrder extends ViewRecord
{
    protected static string $resource = SubOrderResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->after(fn () => $this->refreshFormData(['fulfillment_status_id', 'courier_name', 'tracking_number'])),
            SubOrdersTable::fulfilmentActions(),
        );
    }
}
