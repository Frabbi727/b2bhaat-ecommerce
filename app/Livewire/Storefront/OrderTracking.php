<?php

namespace App\Livewire\Storefront;

use App\Models\Order;
use App\Models\SubOrder;
use App\Services\Orders\OrderDisputeService;
use App\Services\Settings\StoreConfigurationService;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.storefront')]
class OrderTracking extends Component
{
    #[Url(as: 'order')]
    public string $orderNumber = '';

    #[Url(as: 'phone')]
    public string $phone = '';

    #[Url(as: 'token')]
    public string $token = '';

    public ?Order $order = null;

    /** Set only by a lookup that proved knowledge of the order (exact phone or secret tracking token). */
    #[Locked]
    public bool $verifiedAccess = false;

    /** The self-delivery handover code is shown only to the signed-in owner or the tracking-token holder (C1). */
    #[Locked]
    public bool $canSeeHandoverCode = false;

    public string $searchError = '';

    public string $actionFeedback = '';

    public string $actionError = '';

    // Return Modal State
    public bool $showReturnModal = false;

    public ?int $selectedSubOrderId = null;

    public string $returnReason = 'defective_item';

    public string $returnRequestedAction = 'refund';

    public string $returnDescription = '';

    public function mount(?string $token = null): void
    {
        $tokenToUse = $token ?: $this->token;
        if (! empty($tokenToUse)) {
            $this->loadOrderByToken($tokenToUse);

            return;
        }

        if ($this->orderNumber) {
            $this->searchOrder();
        }
    }

    public function loadOrderByToken(string $token): void
    {
        $this->searchError = '';
        $this->actionFeedback = '';
        $this->actionError = '';
        $this->order = null;

        $found = Order::with([
            'orderStatus',
            'paymentStatus',
            'paymentMethod',
            'subOrders.seller.sellerProfile',
            'subOrders.fulfillmentStatus',
            'subOrders.trackingLogs.fulfillmentStatus',
            'subOrders.returnRequests',
            'subOrders.items.product',
        ])->where('tracking_token', trim($token))->first();

        if ($found) {
            $this->order = $found;
            $this->orderNumber = $found->order_number;
            $this->verifiedAccess = true;
            $this->canSeeHandoverCode = true;
        } else {
            $this->searchError = 'Invalid or expired tracking token.';
        }
    }

    public function searchOrder(): void
    {
        $this->searchError = '';
        $this->actionFeedback = '';
        $this->actionError = '';
        $this->order = null;
        $this->verifiedAccess = false;
        $this->canSeeHandoverCode = false;

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

        // Guests must prove the exact phone; a signed-in customer may look up their own orders by number.
        if (trim($this->phone) !== '') {
            $query->matchingPhone(trim($this->phone));
        } elseif (auth()->check()) {
            $query->where('customer_id', auth()->id());
        } else {
            $this->searchError = 'Please enter the mobile number used for this order.';

            return;
        }

        $found = $query->first();

        if (! $found) {
            $this->searchError = "No matching order found for #{$trimmedOrderNumber}. Please check details.";

            return;
        }

        $this->order = $found;
        $this->verifiedAccess = true;
        $this->canSeeHandoverCode = auth()->check() && auth()->id() === $found->customer_id;
    }

    /**
     * Cancel and return actions need the signed-in owner, or a lookup verified by exact phone or tracking token.
     */
    private function canModifyOrder(): bool
    {
        if (! $this->order) {
            return false;
        }

        if (auth()->check()) {
            return auth()->id() === $this->order->customer_id;
        }

        return $this->verifiedAccess;
    }

    public function cancelEntireOrder(OrderDisputeService $disputeService): void
    {
        if (! $this->canModifyOrder()) {
            $this->actionError = 'Please sign in or verify the order with its mobile number first.';

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
        if (! $this->canModifyOrder()) {
            $this->actionError = 'Please sign in or verify the order with its mobile number first.';

            return;
        }

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

        if (! $this->canModifyOrder()) {
            $this->actionError = 'Please sign in or verify the order with its mobile number first.';

            return;
        }

        $subOrder = SubOrder::with('order.customer')
            ->where('parent_order_id', $this->order->id)
            ->find($this->selectedSubOrderId);
        if (! $subOrder) {
            $this->actionError = 'Invalid consignment package.';

            return;
        }

        $user = auth()->user() ?? $this->order->customer;
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
            'returnWindowHours' => app(StoreConfigurationService::class)->getEscrowHoldingPeriodHours(),
        ]);
    }
}
