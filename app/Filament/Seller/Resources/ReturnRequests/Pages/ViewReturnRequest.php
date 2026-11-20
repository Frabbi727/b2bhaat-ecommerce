<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Pages;

use App\Filament\Seller\Resources\ReturnRequests\ReturnRequestResource;
use App\Filament\Seller\Resources\ReturnRequests\Tables\ReturnRequestsTable;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One return: what the buyer wants and why, what is coming back, and the approve / reject decision.
 */
class ViewReturnRequest extends ViewRecord
{
    protected static string $resource = ReturnRequestResource::class;

    public const RELATIONS = [
        'customer',
        'items.orderItem.product.primaryImage',
        'items.orderItem.product.unit',
        'items.orderItem.variation.attributeValues',
        'subOrder.fulfillmentStatus',
        'subOrder.items.product.primaryImage',
        'subOrder.items.product.unit',
        'subOrder.items.variation.attributeValues',
        'subOrder.order.paymentMethod',
        'subOrder.replacements',
    ];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(self::RELATIONS);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Return for '.$this->getRecord()->subOrder?->sub_order_number;
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return collect([$record->customer?->name, 'asked '.$record->created_at?->diffForHumans()])->filter()->implode(' · ');
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->after(fn () => $this->getRecord()->refresh()->load(self::RELATIONS)),
            ReturnRequestsTable::decisionActions(),
        );
    }
}
