<?php

namespace App\Livewire\Storefront;

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.storefront')]
class OrderTracking extends Component
{
    #[Url(as: 'order')]
    public string $orderNumber = '';

    #[Url(as: 'phone')]
    public string $phone = '';

    public ?Order $order = null;

    public string $searchError = '';

    public function mount(): void
    {
        if ($this->orderNumber) {
            $this->searchOrder();
        }
    }

    public function searchOrder(): void
    {
        $this->searchError = '';
        $this->order = null;

        $trimmedOrderNumber = trim($this->orderNumber);
        if (! $trimmedOrderNumber) {
            $this->searchError = 'Please enter an order number.';

            return;
        }

        $query = Order::with([
            'orderStatus',
            'paymentStatus',
            'paymentMethod',
            'subOrders.seller.sellerProfile',
            'subOrders.fulfillmentStatus',
            'subOrders.trackingLogs.fulfillmentStatus',
            'subOrders.items.product',
        ])->where(function ($q) use ($trimmedOrderNumber) {
            $q->where('order_number', $trimmedOrderNumber)
                ->orWhereHas('subOrders', function ($sq) use ($trimmedOrderNumber) {
                    $sq->where('sub_order_number', $trimmedOrderNumber)
                        ->orWhere('tracking_number', $trimmedOrderNumber);
                });
        });

        if ($this->phone) {
            $cleanedPhone = trim($this->phone);
            $query->where(function ($q) use ($cleanedPhone) {
                $q->where('shipping_address->recipient_phone', 'like', "%{$cleanedPhone}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', "%{$cleanedPhone}%"));
            });
        }

        $found = $query->first();

        if (! $found) {
            $this->searchError = "No matching order found for #{$trimmedOrderNumber}. Please check details.";

            return;
        }

        $this->order = $found;
    }

    public function render()
    {
        return view('livewire.storefront.order-tracking', [
            'order' => $this->order,
        ]);
    }
}
