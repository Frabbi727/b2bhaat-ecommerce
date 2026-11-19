<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Filament\Seller\Resources\SubOrders\Tables\SubOrdersTable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Sellers can read a sub-order but never edit its money, ownership or parent (PRA-005). The delivery steps (dispatch,
 * handover code) are buttons here as well as in the orders list.
 */
class ViewSubOrder extends ViewRecord
{
    protected static string $resource = SubOrderResource::class;

    public const RELATIONS = [
        'order.customer',
        'order.paymentMethod',
        'order.paymentStatus',
        'fulfillmentStatus',
        'items.product.unit',
        'items.variation.attributeValues',
        'items.salesChannel',
        'trackingLogs.fulfillmentStatus',
        'replaces',
    ];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(self::RELATIONS);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Order '.$this->getRecord()->sub_order_number;
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return collect([
            $record->order?->shipping_address['recipient_name'] ?? $record->order?->customer?->name,
            'received '.$record->created_at?->diffForHumans(),
        ])->filter()->implode(' · ');
    }

    /** Printing, resending the code and declining sit under "More" so the next delivery step stands out. */
    public const SECONDARY_ACTIONS = ['printShippingLabel', 'printDeliveryChallan', 'resendHandoverCode', 'declineParcel'];

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        $actions = collect(SubOrdersTable::fulfilmentActions())
            ->map(fn (Action $action): Action => $action->after(fn () => $this->getRecord()->refresh()->load(self::RELATIONS)));

        [$secondary, $primary] = $actions->partition(fn (Action $action): bool => in_array($action->getName(), self::SECONDARY_ACTIONS, true));

        return [
            ...$primary->values()->all(),
            ActionGroup::make($secondary->values()->all())
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }
}
