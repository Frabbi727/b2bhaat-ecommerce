<?php

namespace App\Livewire\Storefront;

use App\Models\Order;
use App\Models\SubOrder;
use App\Services\Orders\OrderDisputeService;
use Exception;
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

    public string $actionFeedback = '';

    public string $actionError = '';

    // Return Modal State
    public bool $showReturnModal = false;

    public ?int $selectedSubOrderId = null;

    public string $returnReason = 'defective_item';

    public string $returnRequestedAction = 'refund';

    public string $returnDescription = '';

    public function mount(): void
    {
        if ($this->orderNumber) {
            $this->searchOrder();
        }
    }

    public function searchOrder(): void
    {
        $this->searchError = '';
        $this->actionFeedback = '';
        $this->actionError = '';
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
            'subOrders.returnRequests',
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

    public function cancelEntireOrder(OrderDisputeService $disputeService): void
    {
        if (! $this->order) {
            return;
        }

        try {
            $disputeService->cancelFullOrder($this->order, 'Customer requested cancellation via tracking portal');
            $this->actionFeedback = 'Your order has been cancelled and reserved items restored to inventory.';
            $this->searchOrder();
        } catch (Exception $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function cancelSubOrderConsignment(int $subOrderId, OrderDisputeService $disputeService): void
    {
        $subOrder = SubOrder::where('id', $subOrderId)->where('parent_order_id', $this->order?->id)->first();
        if (! $subOrder) {
            $this->actionError = 'Consignment not found.';

            return;
        }

        try {
            $disputeService->cancelSubOrder($subOrder, 'Customer cancelled consignment package');
            $this->actionFeedback = "Consignment #{$subOrder->sub_order_number} has been cancelled.";
            $this->searchOrder();
        } catch (Exception $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function openReturnModal(int $subOrderId): void
    {
        $this->selectedSubOrderId = $subOrderId;
        $this->showReturnModal = true;
        $this->returnDescription = '';
        $this->actionError = '';
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->selectedSubOrderId = null;
    }

    public function submitReturnRequest(OrderDisputeService $disputeService): void
    {
        $this->validate([
            'returnReason' => 'required|string',
            'returnRequestedAction' => 'required|string|in:refund,replacement',
            'returnDescription' => 'required|string|min:10|max:1000',
        ], [
            'returnDescription.min' => 'Please provide at least 10 characters explaining the issue.',
        ]);

        $subOrder = SubOrder::with('order.customer')->find($this->selectedSubOrderId);
        if (! $subOrder) {
            $this->actionError = 'Invalid consignment package.';

            return;
        }

        $user = auth()->user() ?? $subOrder->order?->customer;
        if (! $user) {
            $this->actionError = 'Please sign in or provide verified customer details to submit a return.';

            return;
        }

        try {
            $disputeService->submitReturnRequest($subOrder, $user, [
                'reason' => $this->returnReason,
                'requested_action' => $this->returnRequestedAction,
                'description' => $this->returnDescription,
            ]);

            $this->showReturnModal = false;
            $this->actionFeedback = "Return request initiated for #{$subOrder->sub_order_number}. Escrow payout held pending resolution.";
            $this->searchOrder();
        } catch (Exception $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.storefront.order-tracking', [
            'order' => $this->order,
        ]);
    }
}
