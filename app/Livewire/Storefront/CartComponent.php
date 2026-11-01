<?php

namespace App\Livewire\Storefront;

use App\Models\Wishlist;
use App\Services\Cart\CartSessionService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartComponent extends Component
{
    public string $errorMessage = '';

    public string $couponCode = '';

    public string $couponSuccessMessage = '';

    public string $couponErrorMessage = '';

    public function applyCoupon(): void
    {
        $this->couponErrorMessage = '';
        $this->couponSuccessMessage = '';

        $code = trim($this->couponCode);
        if ($code === '') {
            $this->couponErrorMessage = 'Please enter a coupon code.';

            return;
        }

        $cart = app(CartSessionService::class);
        $result = $cart->applyCoupon($code, Auth::id());

        if ($result['valid']) {
            $this->couponSuccessMessage = $result['message'];
            $this->couponCode = '';
        } else {
            $this->couponErrorMessage = $result['message'] ?? 'Invalid coupon code.';
        }
    }

    public function removeCoupon(): void
    {
        $cart = app(CartSessionService::class);
        $cart->removeCoupon();
        $this->couponSuccessMessage = '';
        $this->couponErrorMessage = '';
    }

    public function moveToWishlist(string $key, int $productId): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('filament.seller.auth.login');

            return;
        }

        Wishlist::firstOrCreate([
            'user_id' => Auth::id(),
            'product_id' => $productId,
        ]);

        $this->removeItem($key);
        $this->couponSuccessMessage = 'Item moved to your wishlist.';
    }

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
        $appliedCoupon = $cart->getCoupon();
        $discountAmount = $cart->getDiscountAmount();
        $subtotalAfterDiscount = $cart->getSubtotalAfterDiscount();

        return view('livewire.storefront.cart-component', [
            'items' => $items,
            'subtotal' => $subtotal,
            'itemCount' => $itemCount,
            'appliedCoupon' => $appliedCoupon,
            'discountAmount' => $discountAmount,
            'subtotalAfterDiscount' => $subtotalAfterDiscount,
        ]);
    }
}
