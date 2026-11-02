<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\Order;
use App\Models\SubOrder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class CustomerOrders extends Component
{
    use WithPagination;

    public string $statusFilter = 'all';

    public string $searchQuery = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearchQuery(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('catalog.index');
        }
    }

    public function render()
    {
        $user = Auth::user();
        $userId = $user?->id;

        $totalOrdersCount = $userId ? Order::where('customer_id', $userId)->count() : 0;
        $totalSpentAmount = $userId ? (float) Order::where('customer_id', $userId)
            ->whereHas('paymentStatus', fn ($q) => $q->where('slug', 'paid'))
            ->sum('total_amount') : 0.00;

        $inTransitCount = $userId ? SubOrder::whereHas('order', fn ($q) => $q->where('customer_id', $userId))
            ->whereHas('fulfillmentStatus', fn ($q) => $q->whereIn('slug', ['shipped', 'in_transit', 'out_for_delivery']))
            ->count() : 0;

        $query = Order::with([
            'orderStatus',
            'paymentStatus',
            'paymentMethod',
            'subOrders.seller.sellerProfile',
            'subOrders.fulfillmentStatus',
            'subOrders.items.product',
        ])
            ->where('customer_id', $userId);

        if ($this->statusFilter !== 'all') {
            $query->whereHas('orderStatus', fn ($q) => $q->where('slug', $this->statusFilter));
        }

        if (trim($this->searchQuery) !== '') {
            $term = trim($this->searchQuery);
            $query->where(function ($q) use ($term) {
                $q->where('order_number', 'like', "%{$term}%")
                    ->orWhereHas('subOrders.items.product', fn ($pq) => $pq->where('title', 'like', "%{$term}%"));
            });
        }

        $orders = $query->latest()->paginate(10);

        return view('livewire.storefront.customer-orders', [
            'orders' => $orders,
            'user' => $user,
            'totalOrdersCount' => $totalOrdersCount,
            'totalSpentAmount' => $totalSpentAmount,
            'inTransitCount' => $inTransitCount,
        ]);
    }
}
