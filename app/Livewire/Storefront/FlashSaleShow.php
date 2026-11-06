<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Enums\FlashSaleStatus;
use App\Models\FlashSale;
use App\Services\Promotions\FlashSalePricing;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class FlashSaleShow extends Component
{
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render()
    {
        $sale = FlashSale::where('slug', $this->slug)->first();

        if (! $sale || in_array($sale->status, [FlashSaleStatus::Draft, FlashSaleStatus::Cancelled], true)) {
            abort(404);
        }

        $effective = $sale->effectiveStatus();

        $products = $sale->products()
            ->with(['primaryImage', 'brand', 'prices.salesChannel'])
            ->get();

        if ($effective === FlashSaleStatus::Active) {
            app(FlashSalePricing::class)->primeFor($products);
        }

        return view('livewire.storefront.flash-sale-show', [
            'sale' => $sale,
            'effectiveStatus' => $effective,
            'products' => $products,
        ]);
    }
}
