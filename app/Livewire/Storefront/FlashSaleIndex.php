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
            ->with(['products' => fn ($q) => $q->take(6)->with(['primaryImage', 'brand', 'prices.salesChannel'])])
            ->orderByDesc('is_featured')
            ->orderByDesc('display_priority')
            ->orderBy('ends_at')
            ->get();

        $upcomingSales = FlashSale::query()->upcoming()
            ->with(['products' => fn ($q) => $q->take(6)->with(['primaryImage', 'brand', 'prices.salesChannel'])])
            ->orderByDesc('is_featured')
            ->orderByDesc('display_priority')
            ->orderBy('starts_at')
            ->get();

        $pricing = app(FlashSalePricing::class);
        $allLiveProducts = $liveSales->pluck('products')->flatten();
        $pricing->primeFor($allLiveProducts);

        return view('livewire.storefront.flash-sale-index', [
            'liveSales' => $liveSales,
            'upcomingSales' => $upcomingSales,
        ]);
    }
}
