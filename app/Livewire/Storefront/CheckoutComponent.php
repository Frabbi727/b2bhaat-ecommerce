<?php

namespace App\Livewire\Storefront;

use App\Actions\Checkout\ResolveCheckoutCustomer;
use App\Events\Orders\OrderPlacedEvent;
use App\Exceptions\FlashSaleUnavailableException;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use App\Services\Cart\CouponService;
use App\Services\Email\EmailRecipientResolver;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\BangladeshDistricts;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Orders\OrderSplitterService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

    public string $recipientEmail = '';

    public string $streetAddress = '';

    public string $city = 'Dhaka';

    public string $postalCode = '';

    public string $deliveryZone = 'inside_dhaka'; // one of ShippingCalculatorService::ZONES

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
            $this->recipientPhone = $user->phone ?? '';
            $this->mfsSenderNumber = $user->phone ?? '';

            $resolver = app(EmailRecipientResolver::class);
            if ($user->email && $resolver->isDeliverable($user->email)) {
                $this->recipientEmail = $user->email;
            }

            $defaultAddress = $user->addresses()->where('is_default', true)->first()
                ?? $user->addresses()->first();

            if ($defaultAddress) {
                $this->selectSavedAddress($defaultAddress->id);
            }
        }

        $defaultMethod = PaymentMethod::availableToBuyers()->where('slug', 'cod')->first()
            ?? PaymentMethod::availableToBuyers()->first();
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
                $this->city = BangladeshDistricts::normalize($addr->city) ?? $addr->city;
                $this->postalCode = (string) $addr->postal_code;
                $this->deliveryZone = BangladeshDistricts::defaultZoneFor($this->city);
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

    /**
     * Choosing a district pre-selects the delivery area it belongs to, so nobody outside Dhaka keeps the Dhaka rate.
     */
    public function updatedCity(): void
    {
        $district = BangladeshDistricts::normalize($this->city);
        if ($district === null) {
            return;
        }

        $this->city = $district;
        $this->deliveryZone = BangladeshDistricts::defaultZoneFor($district);
    }

    public function placeOrder(): void
    {
        $this->errorMessage = '';

        $this->validate([
            'recipientName' => 'required|string|min:3|max:255',
            'recipientPhone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
            'recipientEmail' => 'nullable|email|max:255',
            'streetAddress' => 'required|string|min:5',
            'city' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (BangladeshDistricts::normalize((string) $value) === null) {
                    $fail('Please choose your district from the list.');
                }
            }],
            'postalCode' => 'nullable|string|max:20',
            'deliveryZone' => ['required', Rule::in(ShippingCalculatorService::ZONES), function (string $attribute, mixed $value, \Closure $fail): void {
                $district = BangladeshDistricts::normalize($this->city);
                if ($district !== null && ! BangladeshDistricts::allowsZone($district, (string) $value)) {
                    $fail("This delivery area is not available for {$district}. Please choose \"Outside Dhaka\".");
                }
            }],
            'selectedPaymentMethodId' => ['required', Rule::exists('payment_methods', 'id')->where('is_active', true), function (string $attribute, mixed $value, \Closure $fail): void {
                if (! PaymentMethod::availableToBuyers()->whereKey($value)->exists()) {
                    $fail('This payment method is not available right now. Please choose another one.');
                }
            }],
        ], [
            'recipientPhone.regex' => 'Enter an 11-digit Bangladeshi mobile number, for example 01712345678.',
            'recipientName.min' => 'Enter the full name of the person receiving the parcel.',
            'recipientEmail.email' => 'Please enter a valid email address.',
            'streetAddress.min' => 'Add your house, road and area so the courier can find you.',
        ]);

        $this->city = (string) BangladeshDistricts::normalize($this->city);

        $paymentMethod = PaymentMethod::find($this->selectedPaymentMethodId);

        if ($paymentMethod && in_array($paymentMethod->slug, ['bkash', 'nagad'])) {
            $this->validate([
                'mfsSenderNumber' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
                'mfsTransactionId' => 'required|string|min:6|max:40',
            ], [
                'mfsSenderNumber.required' => "Enter the {$paymentMethod->name} number you paid from.",
                'mfsSenderNumber.regex' => 'Enter an 11-digit mobile number, for example 01712345678.',
                'mfsTransactionId.required' => "Enter the transaction ID from your {$paymentMethod->name} confirmation SMS.",
            ]);
        }

        $cart = app(CartSessionService::class);
        $items = $cart->getItems();

        if (empty($items)) {
            $this->errorMessage = 'Your cart is empty.';

            return;
        }

        $changedTitles = $cart->refreshPrices();
        if ($changedTitles !== []) {
            $this->errorMessage = 'Prices changed for '.implode(', ', $changedTitles).' because a flash sale ended. Please check your cart and place the order again.';

            return;
        }
        $items = $cart->getItems();

        $config = app(StoreConfigurationService::class);

        // 1. Guest checkout policy safeguard
        if (! Auth::check() && ! $config->isGuestCheckoutAllowed()) {
            $this->errorMessage = 'Please sign in or create an account to place your order.';

            return;
        }

        // Wholesale MOQ is checked on each product lot (all variations together) — PRA-025
        $unavailableTitles = $cart->unavailableItemTitles();
        if ($unavailableTitles !== []) {
            $this->errorMessage = implode(', ', $unavailableTitles).' is no longer available. Please remove it from your cart to continue.';

            return;
        }

        $moqViolation = $cart->wholesaleMoqViolations()[0] ?? null;
        if ($moqViolation) {
            $this->errorMessage = "Wholesale minimum order quantity for {$moqViolation['title']} is {$moqViolation['moq']} (all variations together). Your cart has {$moqViolation['quantity']}.";

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
            $customer = app(ResolveCheckoutCustomer::class)->handle(Auth::user(), $this->recipientName, $this->recipientPhone);

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

            $cleanEmail = trim(strtolower($this->recipientEmail));

            $shippingAddressPayload = [
                'recipient_name' => $this->recipientName,
                'recipient_phone' => $this->recipientPhone,
                'recipient_email' => $cleanEmail !== '' ? $cleanEmail : null,
                'street_address' => $this->streetAddress,
                'city' => $this->city,
                'postal_code' => $this->postalCode,
                'delivery_zone' => $this->deliveryZone,
            ];

            if (Auth::check() && $cleanEmail !== '') {
                $authUser = Auth::user();
                $resolver = app(EmailRecipientResolver::class);
                if (! $resolver->isDeliverable($authUser->email)) {
                    if (! User::where('email', $cleanEmail)->where('id', '!=', $authUser->id)->exists()) {
                        $authUser->update(['email' => $cleanEmail]);
                    }
                }
            }

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
                        'flash_sale_product_id' => $item['flash_sale_product_id'] ?? null,
                        'original_unit_price' => $item['original_unit_price'] ?? null,
                    ];
                }, $items)),
            ];

            $splitter = app(OrderSplitterService::class);
            $order = $splitter->createAndSplitOrder($customer, $orderPayload);

            $escrowService = app(EscrowLedgerService::class);

            if ($paymentMethod && in_array($paymentMethod->slug, ['bkash', 'nagad'], true)) {
                // Manual MFS: the TrxID is checked by an admin before anything is paid, held or shipped (PRA-006).
                $order->update([
                    'payment_status_id' => PaymentStatus::where('slug', 'awaiting_verification')->firstOrFail()->id,
                    'order_status_id' => OrderStatus::where('slug', 'confirmed')->firstOrFail()->id,
                ]);
            } elseif ($paymentMethod && $paymentMethod->slug === 'mock_gateway') {
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

            // Send transactional Order Confirmation SMS with tokenized invoice & tracking
            try {
                app(OrderNotificationService::class)->sendOrderConfirmation($order);
                // Mobile-wallet orders reach the seller only after the payment check (PaymentVerificationService::markVerified)
                if ($order->paymentStatus?->slug !== 'awaiting_verification') {
                    app(OrderNotificationService::class)->notifySellersOfNewOrder($order);
                }
            } catch (\Throwable $e) {
                Log::warning('Order SMS dispatch failure: '.$e->getMessage());
            }

            // Trigger OrderPlacedEvent for asynchronous email notification dispatching
            try {
                event(new OrderPlacedEvent($order));
            } catch (\Throwable $e) {
                Log::warning('Order email event dispatch failure: '.$e->getMessage());
            }

            $cart->clear();

            $this->redirectRoute('tracking.show', [
                'order' => $order->order_number,
                'phone' => $this->recipientPhone,
            ]);
        } catch (FlashSaleUnavailableException $e) {
            $this->errorMessage = $e->getMessage();
            if ($e->saleEnded) {
                $cart->refreshPrices();
            }
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
        $paymentMethods = PaymentMethod::availableToBuyers()->get();
        $calculator = app(ShippingCalculatorService::class);
        $zoneQuotes = [];
        foreach (ShippingCalculatorService::ZONES as $zone) {
            $zoneQuotes[$zone] = (float) $calculator->calculateShipping($items, $zone)['total_shipping'];
        }
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
            'zoneQuotes' => $zoneQuotes,
            'selectedMethod' => $selectedMethod,
            'receivingNumber' => $selectedMethod ? PaymentMethod::receivingNumberFor($selectedMethod->slug) : null,
            'districts' => BangladeshDistricts::ALL,
            'guestCheckoutBlocked' => ! Auth::check() && ! app(StoreConfigurationService::class)->isGuestCheckoutAllowed(),
            'savedAddresses' => $savedAddresses,
        ]);
    }
}
