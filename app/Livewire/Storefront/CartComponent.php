<?php

namespace App\Livewire\Storefront;

use App\Services\Cart\CartSessionService;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartComponent extends Component
{
    public string $errorMessage = '';

    public function updateQuantity(string $key, int $newQuantity): void
    {
        $this->errorMessage = '';
        $cart = app(CartSessionService::class);

        try {
            $cart->updateQuantity($key, $newQuantity);
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function removeItem(string $key): void
    {
        $cart = app(CartSessionService::class);
        $cart->removeItem($key);
        $this->dispatch('cartUpdated');
    }

    public function clearCart(): void
    {
        $cart = app(CartSessionService::class);
        $cart->clear();
        $this->dispatch('cartUpdated');
    }

    public function render()
    {
        $cart = app(CartSessionService::class);
        $items = $cart->getItems();
        $subtotal = $cart->getSubtotal();
        $itemCount = $cart->getItemCount();

        return view('livewire.storefront.cart-component', [
            'items' => $items,
            'subtotal' => $subtotal,
            'itemCount' => $itemCount,
        ]);
    }
}
