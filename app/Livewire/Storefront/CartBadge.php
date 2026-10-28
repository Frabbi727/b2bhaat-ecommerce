<?php

namespace App\Livewire\Storefront;

use App\Services\Cart\CartSessionService;
use Livewire\Component;

class CartBadge extends Component
{
    protected $listeners = ['cartUpdated' => '$refresh'];

    public function render()
    {
        $cart = app(CartSessionService::class);
        $count = $cart->getItemCount();
        $subtotal = $cart->getSubtotal();

        return view('livewire.storefront.cart-badge', [
            'count' => $count,
            'subtotal' => $subtotal,
        ]);
    }
}
