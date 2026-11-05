<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-8 pb-4 border-b border-slate-200">
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Checkout</h1>
        <p class="text-xs text-slate-500 mt-1">Items from different sellers arrive in separate parcels.</p>
    </div>

    @if ($errorMessage)
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    <form wire:submit="placeOrder" class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Left: Delivery Address & Payment Method Selection -->
        <div class="lg:col-span-7 space-y-6">
            <!-- Shipping Information -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs">1</span>
                        Delivery address
                    </div>
                    @auth
                        <a href="{{ route('customer.addresses') }}" class="text-xs font-semibold text-indigo-600 hover:underline">
                            Manage addresses &rarr;
                        </a>
                    @endauth
                </h3>

                @if ($savedAddresses->isNotEmpty())
                    <div class="space-y-2 pb-2">
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Deliver to a saved address</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach ($savedAddresses as $addr)
                                <div 
                                    wire:click="selectSavedAddress({{ $addr->id }})"
                                    class="p-3.5 rounded-xl border text-xs cursor-pointer transition relative {{ $selectedAddressId === $addr->id ? 'border-indigo-600 bg-indigo-50/60 ring-2 ring-indigo-500/20 shadow-xs' : 'border-slate-200 bg-slate-50/60 hover:bg-slate-100/70' }}"
                                >
                                    <div class="flex items-center justify-between font-bold text-slate-900">
                                        <span>{{ $addr->address_title ?: 'Saved Address' }}</span>
                                        @if ($addr->is_default)
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-black bg-indigo-100 text-indigo-700 uppercase">Default</span>
                                        @endif
                                    </div>
                                    <div class="text-slate-600 font-medium mt-1">{{ $addr->recipient_name }} • {{ $addr->recipient_phone }}</div>
                                    <div class="text-slate-500 text-[11px] mt-0.5 truncate">{{ $addr->street_address }}, {{ $addr->city }}</div>
                                </div>
                            @endforeach

                            <div 
                                wire:click="selectSavedAddress(null)"
                                class="p-3.5 rounded-xl border border-dashed text-xs cursor-pointer transition flex items-center justify-center gap-2 {{ is_null($selectedAddressId) ? 'border-indigo-600 bg-indigo-50/40 text-indigo-700 font-bold' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                <span>Use a new address</span>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Full name *</label>
                        <input 
                            type="text" 
                            wire:model="recipientName" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('recipientName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Mobile number *</label>
                        <input 
                            type="tel" 
                            inputmode="numeric"
                            autocomplete="tel"
                            placeholder="e.g. 01712345678"
                            wire:model="recipientPhone" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('recipientPhone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block font-semibold text-slate-700 mb-1">Address (house, road, area) *</label>
                        <textarea 
                            rows="2" 
                            wire:model="streetAddress" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        ></textarea>
                        @error('streetAddress') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="checkout-district" class="block font-semibold text-slate-700 mb-1">District *</label>
                        <select 
                            id="checkout-district"
                            wire:model.live="city" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl bg-white focus:ring-2 focus:ring-indigo-500"
                        >
                            <option value="">Choose your district</option>
                            @if ($city !== '' && ! in_array($city, $districts, true))
                                <option value="{{ $city }}">{{ $city }}</option>
                            @endif
                            @foreach ($districts as $district)
                                <option value="{{ $district }}">{{ $district }}</option>
                            @endforeach
                        </select>
                        @error('city') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2 space-y-1.5 pt-1">
                        @php
                            $storeConfig = app(\App\Services\Settings\StoreConfigurationService::class);
                        @endphp
                        <label class="block font-semibold text-slate-700">Delivery area *</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            @foreach ($zoneQuotes as $zone => $zoneShipping)
                                <label class="flex items-center justify-between gap-2 p-3 rounded-xl border cursor-pointer transition {{ $deliveryZone === $zone ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:bg-slate-50' }}">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" wire:model.live="deliveryZone" value="{{ $zone }}" class="text-indigo-600 focus:ring-indigo-500"/>
                                        <div>
                                            <div class="font-bold text-slate-900">{{ \App\Services\Logistics\ShippingCalculatorService::ZONE_LABELS[$zone] }}</div>
                                            <div class="text-[11px] text-slate-500">Delivery in {{ $storeConfig->getDeliverySla($zone) }}</div>
                                        </div>
                                    </div>
                                    <span class="font-bold text-indigo-600 font-mono shrink-0">
                                        {{ $zoneShipping <= 0 ? 'Free' : '৳'.number_format($zoneShipping, 0) }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('deliveryZone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="checkout-postal-code" class="block font-semibold text-slate-700 mb-1">Postal code (optional)</label>
                        <input 
                            id="checkout-postal-code"
                            type="text" 
                            inputmode="numeric"
                            wire:model="postalCode" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('postalCode') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    @auth
                        <div class="sm:col-span-2 pt-3 border-t border-slate-100 space-y-2">
                            <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                                <input type="checkbox" wire:model.live="saveToAddressBook" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span>Save this address for next time</span>
                            </label>
                            @if ($saveToAddressBook)
                                <div class="pl-6">
                                    <input 
                                        type="text" 
                                        wire:model="newAddressTitle" 
                                        placeholder="Name this address, e.g. Home or Shop" 
                                        class="w-full text-xs py-1.5 px-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
                                    />
                                </div>
                            @endif
                        </div>
                    @endauth
                </div>
            </div>

            <!-- Payment Method -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3 flex items-center gap-2">
                    <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs">2</span>
                    Payment
                </h3>

                <div class="space-y-3">
                    @foreach ($paymentMethods as $pm)
                        <label class="flex items-center justify-between p-3.5 rounded-xl border cursor-pointer transition {{ $selectedPaymentMethodId === $pm->id ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:bg-slate-50' }}">
                            <div class="flex items-center gap-3">
                                <input 
                                    type="radio" 
                                    name="payment_method" 
                                    wire:model.live="selectedPaymentMethodId" 
                                    value="{{ $pm->id }}" 
                                    class="text-indigo-600 focus:ring-indigo-500"
                                />
                                <div>
                                    <div class="text-xs font-bold text-slate-900">
                                        {{ ['cod' => 'Cash on Delivery', 'bkash' => 'bKash', 'nagad' => 'Nagad'][$pm->slug] ?? $pm->name }}
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        @if ($pm->slug === 'cod')
                                            Pay the delivery person in cash when your parcel arrives.
                                        @elseif (in_array($pm->slug, ['bkash', 'nagad'], true))
                                            Send money first, then enter the transaction ID.
                                        @else
                                            Test payment (only on the development site).
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                {{-- Mobile wallet payment details --}}
                @if ($selectedMethod && in_array($selectedMethod->slug, ['bkash', 'nagad']))
                    <div class="mt-4 p-4 rounded-xl border border-indigo-200 bg-indigo-50/40 space-y-3 text-xs">
                        @php $walletName = $selectedMethod->slug === 'bkash' ? 'bKash' : 'Nagad'; @endphp
                        <div class="font-bold text-slate-900">How to pay with {{ $walletName }}</div>
                        <ol class="list-decimal pl-4 space-y-1 text-slate-600 text-[11px] leading-relaxed">
                            <li>Send <strong class="text-slate-900">৳{{ number_format($grandTotal, 2) }}</strong> to our {{ $walletName }} number <span class="font-mono font-bold text-slate-900 bg-white px-2 py-0.5 rounded border border-slate-200">{{ $receivingNumber }}</span> using "Send Money".</li>
                            <li>Enter the number you paid from and the transaction ID from your {{ $walletName }} message.</li>
                        </ol>
                        <p class="text-slate-500 text-[11px]">We check your payment before the seller sends your order.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Number you paid from *</label>
                                <input 
                                    type="text" 
                                    wire:model="mfsSenderNumber" 
                                    placeholder="01XXXXXXXXX" 
                                    class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono text-xs"
                                />
                                @error('mfsSenderNumber') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Transaction ID *</label>
                                <input 
                                    type="text" 
                                    wire:model="mfsTransactionId" 
                                    placeholder="e.g. 9J82KD8X" 
                                    class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 uppercase font-mono text-xs"
                                />
                                @error('mfsTransactionId') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Right: order summary -->
        <div class="lg:col-span-5 space-y-4">
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3">Your order</h3>

                <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto space-y-2 pr-1">
                    @foreach ($items as $item)
                        <div class="py-2 flex items-center justify-between text-xs gap-3">
                            <div>
                                <div class="font-semibold text-slate-800">{{ $item['product_title'] }}</div>
                                <div class="text-[11px] text-slate-500">Qty {{ $item['quantity'] }} × ৳{{ number_format($item['unit_price'], 2) }}</div>
                                <div class="text-[10px] text-slate-500">Sold by {{ $item['seller_name'] }}</div>
                            </div>
                            <div class="font-extrabold text-slate-900">
                                ৳{{ number_format($item['unit_price'] * $item['quantity'], 2) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Coupon Box in Checkout -->
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                    <div class="flex items-center justify-between text-xs font-bold text-slate-800">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            Coupon
                        </span>
                        @if ($appliedCoupon)
                            <button type="button" wire:click="removeCheckoutCoupon" class="text-rose-600 underline text-[11px] font-semibold hover:text-rose-800">Remove</button>
                        @endif
                    </div>

                    @if ($appliedCoupon)
                        <div class="p-2.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center justify-between">
                            <span class="font-mono font-bold">{{ $appliedCoupon['code'] }}</span>
                            <span class="font-bold">-৳{{ number_format($discountAmount, 2) }}</span>
                        </div>
                    @else
                        <div class="flex gap-2">
                            <input 
                                type="text" 
                                wire:model="checkoutCouponCode" 
                                placeholder="Coupon code" 
                                class="flex-1 uppercase text-xs py-1.5 px-3 bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono"
                            />
                            <button 
                                type="button" 
                                wire:click="applyCheckoutCoupon" 
                                class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-lg transition cursor-pointer"
                            >
                                Apply
                            </button>
                        </div>
                    @endif

                    @if ($couponMessage)
                        <p class="text-[11px] font-semibold text-emerald-600">{{ $couponMessage }}</p>
                    @endif
                    @if ($couponError)
                        <p class="text-[11px] font-semibold text-rose-600">{{ $couponError }}</p>
                    @endif
                </div>

                <div class="border-t border-slate-100 pt-3 space-y-1.5 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Items</span>
                        <span class="font-bold text-slate-900">৳{{ number_format($subtotal, 2) }}</span>
                    </div>
                    @if ($discountAmount > 0)
                        <div class="flex justify-between text-emerald-700 font-semibold">
                            <span>Discount</span>
                            <span class="font-bold font-mono">-৳{{ number_format($discountAmount, 2) }}</span>
                        </div>
                    @endif
                    <div class="space-y-1">
                        <div class="flex justify-between text-slate-600">
                            <span>Delivery</span>
                            @if ($shippingFee == 0)
                                <span class="text-emerald-600 font-bold">Free</span>
                            @else
                                <span class="font-bold text-slate-800 font-mono">৳{{ number_format($shippingFee, 2) }}</span>
                            @endif
                        </div>
                        @if (count($shippingBreakdown['vendors'] ?? []) > 0)
                            <div class="bg-slate-50 rounded-xl p-2.5 space-y-1 text-[11px] border border-slate-100">
                                @foreach ($shippingBreakdown['vendors'] as $v)
                                    <div class="flex justify-between text-slate-500">
                                        <span class="truncate max-w-[180px] font-medium">• {{ $v['store_name'] }}:</span>
                                        <span class="font-semibold {{ $v['is_free'] ? 'text-emerald-600 font-bold' : 'text-slate-700' }}">
                                            {{ $v['is_free'] ? 'Free (over ৳' . number_format($v['threshold']) . ')' : '৳' . number_format($v['shipping_fee'], 2) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="border-t border-slate-200 pt-2 flex justify-between items-baseline">
                        <span class="text-sm font-bold text-slate-900">Total</span>
                        <span class="text-2xl font-black text-indigo-600">৳{{ number_format($grandTotal, 2) }}</span>
                    </div>
                </div>

                <button 
                    type="submit" 
                    wire:loading.attr="disabled"
                    class="w-full py-4 px-4 rounded-xl font-bold text-sm bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                >
                    <svg wire:loading.remove class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <svg wire:loading class="animate-spin w-5 h-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span wire:loading.remove>Place order</span>
                    <span wire:loading>Placing your order…</span>
                </button>
            </div>
        </div>
    </form>
</div>
