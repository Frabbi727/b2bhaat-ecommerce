<?php

namespace App\Livewire\Storefront;

use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Cart\CouponService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Settings\StoreConfigurationService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CheckoutComponent extends Component
{
    public ?int $selectedAddressId = null;

    public bool $saveToAddressBook = false;

    public string $newAddressTitle = 'Office / Warehouse';

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $streetAddress = '';

    public string $city = 'Dhaka';

    public string $postalCode = '1205';

    public string $deliveryZone = 'inside_dhaka'; // 'inside_dhaka' (70 Tk) or 'outside_dhaka' (130 Tk)

    public ?int $selectedPaymentMethodId = null;

    public string $mfsSenderNumber = '';

    public string $mfsTransactionId = '';

    public string $checkoutCouponCode = '';

    public ?array $appliedCoupon = null;

    public string $couponMessage = '';

    public string $couponError = '';

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
            $this->mfsSenderNumber = $user->phone ?? '';
            $defaultAddress = $user->addresses()->where('is_default', true)->first()
                ?? $user->addresses()->first();

            if ($defaultAddress) {
                $this->selectSavedAddress($defaultAddress->id);
            }
        }

        $defaultMethod = PaymentMethod::where('slug', 'cod')->first()
            ?? PaymentMethod::where('slug', 'mock_gateway')->first()
            ?? PaymentMethod::where('is_active', true)->first();
        $this->selectedPaymentMethodId = $defaultMethod?->id;
        $this->appliedCoupon = $cart->getCoupon();
    }

    public function applyCheckoutCoupon(): void
    {
        $this->couponError = '';
        $this->couponMessage = '';

        $code = trim($this->checkoutCouponCode);
        if ($code === '') {
            $this->couponError = 'Please enter a coupon code.';

            return;
        }

        $cart = app(CartSessionService::class);
        $result = $cart->applyCoupon($code, Auth::id());

        if ($result['valid']) {
            $this->couponMessage = $result['message'];
            $this->checkoutCouponCode = '';
            $this->appliedCoupon = $result['coupon'] ? [
                'id' => $result['coupon']->id,
                'code' => $result['coupon']->code,
                'name' => $result['coupon']->name,
                'discount' => $result['discount'],
            ] : null;
        } else {
            $this->couponError = $result['message'] ?? 'Invalid coupon code.';
            $this->appliedCoupon = null;
        }
    }

    public function removeCheckoutCoupon(): void
    {
        $cart = app(CartSessionService::class);
        $cart->removeCoupon();
        $this->appliedCoupon = null;
        $this->couponMessage = '';
        $this->couponError = '';
    }

    public function selectSavedAddress(?int $addressId): void
    {
        $this->selectedAddressId = $addressId;

        if ($addressId && Auth::check()) {
            $addr = Auth::user()->addresses()->find($addressId);
            if ($addr) {
                $this->recipientName = $addr->recipient_name;
                $this->recipientPhone = $addr->recipient_phone;
                $this->streetAddress = $addr->street_address;
                $this->city = $addr->city;
                $this->postalCode = $addr->postal_code;
                $this->deliveryZone = strtolower($addr->city) === 'dhaka' ? 'inside_dhaka' : 'outside_dhaka';
            }
        }
    }

    public function getShippingFee(): float
    {
        $cart = app(CartSessionService::class);
        $items = $cart->getItems();

        $calc = app(ShippingCalculatorService::class)->calculateShipping($items, $this->deliveryZone);

        return (float) $calc['total_shipping'];
    }

    public function getShippingBreakdown(): array
    {
        $cart = app(CartSessionService::class);
        $items = $cart->getItems();

        return app(ShippingCalculatorService::class)->calculateShipping($items, $this->deliveryZone);
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

        $paymentMethod = PaymentMethod::find($this->selectedPaymentMethodId);

        if ($paymentMethod && in_array($paymentMethod->slug, ['bkash', 'nagad'])) {
            $this->validate([
                'mfsSenderNumber' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
                'mfsTransactionId' => 'required|string|min:6|max:40',
            ], [
                'mfsSenderNumber.required' => "Please enter your {$paymentMethod->name} sender mobile number.",
                'mfsTransactionId.required' => "Please provide the transaction ID (TrxID) from your {$paymentMethod->name} payment.",
            ]);
        }

        $cart = app(CartSessionService::class);
        $items = $cart->getItems();

        if (empty($items)) {
            $this->errorMessage = 'Your cart is empty.';

            return;
        }

        $config = app(StoreConfigurationService::class);

        // 1. Guest checkout policy safeguard
        if (! Auth::check() && ! $config->isGuestCheckoutAllowed()) {
            $this->errorMessage = 'Guest checkout is disabled. Please sign in to complete your purchase.';

            return;
        }

        // 2. Minimum order amount safeguards
        $subtotal = $cart->getSubtotal();
        $isWholesale = collect($items)->contains(fn ($i) => ($i['sales_channel_id'] ?? null) === SalesChannel::where('slug', SalesChannel::WHOLESALE)->value('id'));
        $channel = $isWholesale ? 'wholesale' : 'retail';
        $minBasket = $config->getMinOrderAmount($channel);

        if ($subtotal < $minBasket) {
            $this->errorMessage = "Minimum order amount for {$channel} orders is ৳".number_format($minBasket, 0);

            return;
        }

        // 3. Cash on Delivery (COD) maximum order limit safeguard
        $shippingFee = $this->getShippingFee();
        $discountAmount = $cart->getDiscountAmount();
        $grandTotal = max(0.00, round($subtotal + $shippingFee - $discountAmount, 2));

        if ($paymentMethod && $paymentMethod->slug === 'cod' && $grandTotal > $config->getCodMaxLimit()) {
            $this->errorMessage = 'Cash on Delivery is only available for orders up to ৳'.number_format($config->getCodMaxLimit(), 0);

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
            $appliedCoupon = $this->appliedCoupon ?? $cart->getCoupon();
            $discountAmount = $cart->getDiscountAmount();

            if ($appliedCoupon && ! empty($appliedCoupon['code'])) {
                $couponService = app(CouponService::class);
                $validation = $couponService->applyCoupon(
                    code: (string) $appliedCoupon['code'],
                    subtotal: $subtotal,
                    userId: $customer->id
                );

                if (! $validation['valid']) {
                    $cart->removeCoupon();
                    $this->appliedCoupon = null;
                    throw new Exception("Promotional coupon cannot be redeemed: {$validation['message']}");
                }

                $discountAmount = (float) $validation['discount'];
            } else {
                $discountAmount = 0.00;
            }

            $shippingAddressPayload = [
                'recipient_name' => $this->recipientName,
                'recipient_phone' => $this->recipientPhone,
                'street_address' => $this->streetAddress,
                'city' => $this->city,
                'postal_code' => $this->postalCode,
                'delivery_zone' => $this->deliveryZone,
            ];

            if ($paymentMethod && in_array($paymentMethod->slug, ['bkash', 'nagad'])) {
                $shippingAddressPayload['mfs_gateway'] = $paymentMethod->slug;
                $shippingAddressPayload['mfs_sender_number'] = $this->mfsSenderNumber;
                $shippingAddressPayload['mfs_transaction_id'] = $this->mfsTransactionId;
            }

            $orderPayload = [
                'payment_method_id' => $this->selectedPaymentMethodId,
                'shipping_amount' => $shippingFee,
                'coupon_id' => $appliedCoupon['id'] ?? null,
                'coupon_code' => $appliedCoupon['code'] ?? null,
                'discount_amount' => $discountAmount,
                'shipping_address' => $shippingAddressPayload,
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

            $escrowService = app(EscrowLedgerService::class);

            if ($paymentMethod && in_array($paymentMethod->slug, ['mock_gateway', 'bkash', 'nagad', 'sslcommerz'])) {
                $paidStatus = PaymentStatus::where('slug', 'paid')->firstOrFail();
                $confirmedStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();

                $order->update([
                    'payment_status_id' => $paidStatus->id,
                    'order_status_id' => $confirmedStatus->id,
                ]);

                foreach ($order->subOrders as $subOrder) {
                    $escrowService->holdInEscrow($subOrder);
                }
            } elseif ($paymentMethod && $paymentMethod->slug === 'cod') {
                $confirmedStatus = OrderStatus::where('slug', 'confirmed')->firstOrFail();
                $order->update([
                    'order_status_id' => $confirmedStatus->id,
                ]);
            }

            // Record coupon usage if applied
            if ($appliedCoupon && ! empty($appliedCoupon['id'])) {
                $couponModel = Coupon::find($appliedCoupon['id']);
                if ($couponModel) {
                    app(CouponService::class)->recordUsage(
                        coupon: $couponModel,
                        userId: $customer->id,
                        orderId: $order->id,
                        discountAmount: $discountAmount
                    );
                }
            }

            // Save address to address book if requested by customer
            if ($this->saveToAddressBook && Auth::check()) {
                CustomerAddress::create([
                    'user_id' => $customer->id,
                    'address_title' => $this->newAddressTitle ?: 'Consignment Address',
                    'recipient_name' => $this->recipientName,
                    'recipient_phone' => $this->recipientPhone,
                    'street_address' => $this->streetAddress,
                    'city' => $this->city,
                    'postal_code' => $this->postalCode,
                    'is_default' => $customer->addresses()->count() === 0,
                ]);
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
        $appliedCoupon = $cart->getCoupon();
        $discountAmount = $cart->getDiscountAmount();
        $shippingBreakdown = $this->getShippingBreakdown();
        $shippingFee = (float) $shippingBreakdown['total_shipping'];
        $grandTotal = max(0.00, round($subtotal + $shippingFee - $discountAmount, 2));
        $paymentMethods = PaymentMethod::where('is_active', true)->get();
        $selectedMethod = PaymentMethod::find($this->selectedPaymentMethodId);
        $savedAddresses = Auth::check() ? Auth::user()->addresses()->latest()->get() : collect();

        return view('livewire.storefront.checkout-component', [
            'items' => $items,
            'subtotal' => $subtotal,
            'appliedCoupon' => $appliedCoupon,
            'discountAmount' => $discountAmount,
            'shippingFee' => $shippingFee,
            'shippingBreakdown' => $shippingBreakdown,
            'grandTotal' => $grandTotal,
            'paymentMethods' => $paymentMethods,
            'selectedMethod' => $selectedMethod,
            'savedAddresses' => $savedAddresses,
        ]);
    }
}
