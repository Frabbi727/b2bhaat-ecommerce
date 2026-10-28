<?php

namespace App\Livewire\Storefront;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Orders\OrderSplitterService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CheckoutComponent extends Component
{
    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $streetAddress = '';

    public string $city = 'Dhaka';

    public string $postalCode = '1205';

    public string $deliveryZone = 'inside_dhaka'; // 'inside_dhaka' (70 Tk) or 'outside_dhaka' (130 Tk)

    public ?int $selectedPaymentMethodId = null;

    public string $errorMessage = '';

    public function mount(): void
    {
        $cart = app(CartSessionService::class);
        if ($cart->getItemCount() === 0) {
            $this->redirectRoute('cart.index');

            return;
        }

        $user = Auth::user();
        if ($user) {
            $this->recipientName = $user->name;
            $this->recipientPhone = $user->phone ?? '+8801700000004';
            $defaultAddress = $user->addresses()->where('is_default', true)->first();
            if ($defaultAddress) {
                $this->streetAddress = $defaultAddress->street_address;
                $this->city = $defaultAddress->city;
                $this->postalCode = $defaultAddress->postal_code;
                if (strtolower($defaultAddress->city) !== 'dhaka') {
                    $this->deliveryZone = 'outside_dhaka';
                }
            }
        }

        $defaultMethod = PaymentMethod::where('slug', 'mock_gateway')->first()
            ?? PaymentMethod::where('is_active', true)->first();
        $this->selectedPaymentMethodId = $defaultMethod?->id;
    }

    public function getShippingFee(): float
    {
        $cart = app(CartSessionService::class);
        $subtotal = $cart->getSubtotal();

        // Wholesale bulk order free delivery incentive
        if ($subtotal >= 10000.0) {
            return 0.0;
        }

        return $this->deliveryZone === 'outside_dhaka' ? 130.0 : 70.0;
    }

    public function updatedDeliveryZone(): void
    {
        if ($this->deliveryZone === 'inside_dhaka' && $this->city !== 'Dhaka') {
            $this->city = 'Dhaka';
        }
    }

    public function placeOrder(): void
    {
        $this->errorMessage = '';

        $this->validate([
            'recipientName' => 'required|string|min:3|max:255',
            'recipientPhone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
            'streetAddress' => 'required|string|min:5',
            'city' => 'required|string|max:100',
            'postalCode' => 'required|string|max:20',
            'deliveryZone' => 'required|in:inside_dhaka,outside_dhaka',
            'selectedPaymentMethodId' => 'required|exists:payment_methods,id',
        ], [
            'recipientPhone.regex' => 'Please enter a valid Bangladeshi phone number (e.g., 017XXXXXXXX or +88017XXXXXXXX).',
            'recipientName.min' => 'Please enter a valid full recipient or business name.',
            'streetAddress.min' => 'Please provide a detailed street, road, or warehouse address.',
        ]);

        $cart = app(CartSessionService::class);
        $items = $cart->getItems();

        if (empty($items)) {
            $this->errorMessage = 'Your cart is empty.';

            return;
        }

        try {
            // Determine customer
            $customer = Auth::user();
            if (! $customer) {
                $retailRole = Role::where('slug', 'retail_buyer')->firstOrFail();
                $customer = User::firstOrCreate(
                    ['email' => 'guest_'.time().'@b2bhaat.com'],
                    [
                        'role_id' => $retailRole->id,
                        'name' => $this->recipientName,
                        'phone' => $this->recipientPhone,
                        'password' => Hash::make('password'),
                        'is_active' => true,
                    ]
                );
            }

            $shippingFee = $this->getShippingFee();

            $orderPayload = [
                'payment_method_id' => $this->selectedPaymentMethodId,
                'shipping_amount' => $shippingFee,
                'shipping_address' => [
                    'recipient_name' => $this->recipientName,
                    'recipient_phone' => $this->recipientPhone,
                    'street_address' => $this->streetAddress,
                    'city' => $this->city,
                    'postal_code' => $this->postalCode,
                    'delivery_zone' => $this->deliveryZone,
                ],
                'items' => array_values(array_map(function ($item) {
                    return [
                        'product_id' => $item['product_id'],
                        'variation_id' => $item['variation_id'],
                        'sales_channel_id' => $item['sales_channel_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ];
                }, $items)),
            ];

            $splitter = app(OrderSplitterService::class);
            $order = $splitter->createAndSplitOrder($customer, $orderPayload);

            $paymentMethod = PaymentMethod::find($this->selectedPaymentMethodId);
            $escrowService = app(EscrowLedgerService::class);

            if ($paymentMethod && in_array($paymentMethod->slug, ['mock_gateway', 'bkash', 'sslcommerz'])) {
                $paidStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
                $confirmedStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();

                $order->update([
                    'payment_status_id' => $paidStatus->id,
                    'order_status_id' => $confirmedStatus->id,
                ]);

                foreach ($order->subOrders as $subOrder) {
                    $escrowService->holdInEscrow($subOrder);
                }
            }

            $cart->clear();

            $this->redirectRoute('tracking.show', [
                'order' => $order->order_number,
                'phone' => $this->recipientPhone,
            ]);
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        $cart = app(CartSessionService::class);
        $items = $cart->getItems();
        $subtotal = $cart->getSubtotal();
        $shippingFee = $this->getShippingFee();
        $grandTotal = $subtotal + $shippingFee;
        $paymentMethods = PaymentMethod::where('is_active', true)->get();

        return view('livewire.storefront.checkout-component', [
            'items' => $items,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'grandTotal' => $grandTotal,
            'paymentMethods' => $paymentMethods,
        ]);
    }
}
