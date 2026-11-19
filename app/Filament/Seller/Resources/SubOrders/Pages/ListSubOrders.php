<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\FulfillmentStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The seller's orders, split into the stage of work each one is at: pack it, send it, wait for delivery, done.
 */
class ListSubOrders extends ListRecords
{
    protected static string $resource = SubOrderResource::class;

    /** Tab key => label and the fulfilment statuses it holds; the first two need the seller to act. */
    public const STAGES = [
        'to_pack' => ['label' => 'To pack', 'statuses' => ['unfulfilled'], 'needs_action' => true],
        'ready_to_ship' => ['label' => 'Ready to ship', 'statuses' => ['processing'], 'needs_action' => true],
        'on_the_way' => ['label' => 'On the way', 'statuses' => ['shipped', 'in_transit', 'out_for_delivery'], 'needs_action' => false],
        'delivered' => ['label' => 'Delivered', 'statuses' => ['delivered'], 'needs_action' => false],
        'closed' => ['label' => 'Cancelled & returned', 'statuses' => ['cancelled', 'returned'], 'needs_action' => false],
    ];

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $countsBySlug = SubOrderResource::getEloquentQuery()
            ->join('fulfillment_statuses', 'fulfillment_statuses.id', '=', 'sub_orders.fulfillment_status_id')
            ->selectRaw('fulfillment_statuses.slug as slug, count(*) as aggregate')
            ->groupBy('fulfillment_statuses.slug')
            ->pluck('aggregate', 'slug');

        $tabs = ['all' => Tab::make('All')];

        foreach (self::STAGES as $key => $stage) {
            $count = (int) collect($stage['statuses'])->sum(fn (string $slug): int => (int) ($countsBySlug[$slug] ?? 0));

            $tabs[$key] = Tab::make($stage['label'])
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn(
                    'fulfillment_status_id',
                    FulfillmentStatus::whereIn('slug', $stage['statuses'])->select('id'),
                ))
                ->badge($stage['needs_action'] && $count > 0 ? $count : null)
                ->badgeColor('warning');
        }

        return $tabs;
    }
}
