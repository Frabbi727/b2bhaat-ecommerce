<?php

namespace App\Livewire\Storefront;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CustomerOrders extends Component
{
    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('catalog.index');
        }
    }

    public function render()
    {
        $user = Auth::user();

        $orders = Order::with([
            'orderStatus',
            'paymentStatus',
            'paymentMethod',
            'subOrders.seller.sellerProfile',
            'subOrders.fulfillmentStatus',
            'subOrders.items.product',
        ])
            ->where('customer_id', $user?->id)
            ->latest()
            ->paginate(10);

        return view('livewire.storefront.customer-orders', [
            'orders' => $orders,
            'user' => $user,
        ]);
    }
}
