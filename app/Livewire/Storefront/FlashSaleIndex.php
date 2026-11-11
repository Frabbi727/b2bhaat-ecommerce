<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\FlashSale;
use App\Services\Promotions\FlashSalePricing;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class FlashSaleIndex extends Component
{
    public function render()
    {
        $liveSales = FlashSale::query()->live()
            ->withCount(['approvedProducts' => fn ($q) => $q->listable()])
            ->with(['approvedProducts' => fn ($q) => $q->listable()->take(4)->with(['primaryImage', 'brand', 'prices.salesChannel', 'variations', 'seller.sellerProfile', 'salesChannel'])])
            ->orderByDesc('is_featured')
            ->orderByDesc('display_priority')
            ->orderBy('ends_at')
            ->get();

        $upcomingSales = FlashSale::query()->upcoming()
            ->with(['approvedProducts' => fn ($q) => $q->listable()->take(6)->with(['primaryImage'])])
            ->orderByDesc('is_featured')
            ->orderByDesc('display_priority')
            ->orderBy('starts_at')
            ->get();

        $liveSales->each(function (FlashSale $sale): void {
            $sale->setRelation('products', $sale->approvedProducts);
        });
        $upcomingSales->each(function (FlashSale $sale): void {
            $sale->setRelation('products', $sale->approvedProducts);
        });

        $pricing = app(FlashSalePricing::class);
        $allLiveProducts = $liveSales->pluck('products')->flatten();
        $pricing->primeFor($allLiveProducts);

        return view('livewire.storefront.flash-sale-index', [
            'liveSales' => $liveSales,
            'upcomingSales' => $upcomingSales,
        ]);
    }
}
