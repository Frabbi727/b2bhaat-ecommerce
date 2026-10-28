<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Cart\CartSessionService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CustomerWishlist extends Component
{
    public string $feedbackMessage = '';

    public string $errorMessage = '';

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('filament.seller.auth.login');
        }
    }

    public function removeFromWishlist(int $wishlistId): void
    {
        $wishlist = Auth::user()->wishlists()->findOrFail($wishlistId);
        $wishlist->delete();

        $this->feedbackMessage = 'Item removed from your wishlist.';
    }

    public function quickAddToCart(int $productId, CartSessionService $cart): void
    {
        $user = Auth::user();
        $isWholesale = $user && ($user->isWholesaleBuyer() || $user->is_wholesale_approved);
        $channel = $isWholesale ? SalesChannel::WHOLESALE : SalesChannel::RETAIL;

        try {
            $product = Product::with('variations')->findOrFail($productId);
            $variationId = $product->has_variations && $product->variations->isNotEmpty()
                ? $product->variations->first()->id
                : null;

            $cart->addItem(
                productId: $productId,
                variationId: $variationId,
                channelSlug: $channel,
                quantity: 1
            );

            $this->dispatch('cartUpdated');
            $this->feedbackMessage = 'Product added to your cart successfully!';
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        $wishlists = Auth::check()
            ? Auth::user()->wishlists()
                ->with([
                    'product.seller.sellerProfile',
                    'product.images',
                    'product.category',
                    'product.retailPrice',
                    'product.wholesalePrice',
                    'product.variations',
                ])
                ->latest()
                ->get()
            : collect();

        return view('livewire.storefront.customer-wishlist', [
            'wishlists' => $wishlists,
        ]);
    }
}
