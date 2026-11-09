<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Orders\Tables\OrdersTable;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Order '.$this->getRecord()->order_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            OrdersTable::verifyPaymentAction(),
            ActionGroup::make([
                OrdersTable::invoiceAction(),
                OrdersTable::resendInvoiceSmsAction(),
                OrdersTable::rejectPaymentAction(),
            ])
                ->label('More')
                ->button()
                ->color('gray'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->getRecord()->loadMissing(['subOrders.items.product', 'subOrders.items.variation', 'subOrders.seller.sellerProfile', 'subOrders.fulfillmentStatus']);

        return $data;
    }
}
