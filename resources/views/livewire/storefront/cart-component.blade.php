<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-200">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Your cart</h1>
            <p class="text-xs text-slate-500 mt-1">Check your items and quantities, then go to checkout.</p>
        </div>

        @if (! empty($items))
            <button 
                wire:click="clearCart" wire:confirm="Remove all items from your cart?" 
                class="text-xs text-rose-600 hover:text-rose-700 font-semibold underline"
            >
                Remove all items
            </button>
        @endif
    </div>

    @if ($errorMessage)
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    @if ($couponSuccessMessage)
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span>{{ $couponSuccessMessage }}</span>
            </div>
            <button wire:click="removeCoupon" class="text-xs underline text-emerald-900 font-bold hover:text-emerald-700">Remove</button>
        </div>
    @endif

    @if ($couponErrorMessage)
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $couponErrorMessage }}</span>
        </div>
    @endif

    @if (empty($items))
        <div class="py-16 text-center space-y-4 bg-white rounded-2xl border border-slate-200 p-8">
            <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800">Your cart is empty</h3>
            <p class="text-xs text-slate-500 max-w-sm mx-auto">Find something you like and add it to your cart.</p>
            <a href="{{ route('catalog.index') }}" class="inline-block mt-2 px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition shadow-xs">
                Start shopping
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Left: Cart Items List -->
            <div class="lg:col-span-8 space-y-4">
                @php $shortfallShown = []; @endphp
                @foreach ($items as $item)
                    @php
                        $lineTotal = $item['unit_price'] * $item['quantity'];
                        $isWholesale = $item['sales_channel_slug'] === 'wholesale';
                        $shortfall = $isWholesale && ! ($item['is_quoted'] ?? false) && ! isset($shortfallShown[$item['product_id']])
                            ? ($wholesaleShortfalls[$item['product_id']] ?? null)
                            : null;
                        if ($shortfall) {
                            $shortfallShown[$item['product_id']] = true;
                        }
                    @endphp
                    <div class="p-4 rounded-xl bg-white border border-slate-200 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ $isWholesale ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800' }}">
                                    {{ $isWholesale ? 'Wholesale' : 'Retail' }}
                                </span>
                            </div>

                            <h4 class="font-bold text-slate-900 text-sm">
                                <a href="{{ route('catalog.show', $item['product_slug']) }}" class="hover:underline">
                                    {{ $item['product_title'] }}
                                </a>
                            </h4>

                            @if ($item['attributes_summary'])
                                <div class="text-xs text-slate-500">{{ $item['attributes_summary'] }}</div>
                            @endif

                            <div class="text-xs text-slate-500">
                                Sold by <strong class="text-slate-700">{{ $item['seller_name'] }}</strong>
                            </div>

                            @if ($shortfall)
                                <p class="mt-1 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1" role="status">
                                    Wholesale minimum for this product is {{ $shortfall['moq'] }} units, all options together. You have {{ $shortfall['quantity'] }}. Add {{ $shortfall['moq'] - $shortfall['quantity'] }} more to check out.
                                </p>
                            @endif
                        </div>

                        <!-- Quantity and Line Pricing -->
                        <div class="flex items-center justify-between sm:justify-end gap-6 w-full sm:w-auto">
                            <div>
                                <span class="text-xs text-slate-400 block sm:text-right">Price each</span>
                                <span class="text-xs font-bold text-slate-700">৳{{ number_format($item['unit_price'], 2) }}</span>
                            </div>

                            <!-- Quantity adjustment -->
                            <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden bg-slate-50">
                                <button 
                                    wire:click="updateQuantity('{{ $item['key'] }}', {{ $item['quantity'] - 1 }})" 
                                    aria-label="Decrease quantity"
                                    class="px-2.5 py-1 text-slate-600 hover:bg-slate-200 font-bold text-xs"
                                >
                                    -
                                </button>
                                <span class="px-3 py-1 font-bold text-xs text-slate-800 min-w-8 text-center bg-white">
                                    {{ $item['quantity'] }}
                                </span>
                                <button 
                                    wire:click="updateQuantity('{{ $item['key'] }}', {{ $item['quantity'] + 1 }})" 
                                    aria-label="Increase quantity"
                                    class="px-2.5 py-1 text-slate-600 hover:bg-slate-200 font-bold text-xs"
                                >
                                    +
                                </button>
                            </div>

                            <div class="text-right min-w-24">
                                <span class="text-xs text-slate-400 block">Total</span>
                                <span class="text-sm font-extrabold text-slate-900">৳{{ number_format($lineTotal, 2) }}</span>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    wire:click="moveToWishlist('{{ $item['key'] }}', {{ $item['product_id'] }})"
                                    class="p-1 text-slate-400 hover:text-indigo-600 transition"
                                    title="Save for later" aria-label="Save for later"
                                >
                                    <svg class="w-4 h-4 fill-none stroke-current" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                </button>

                                <button 
                                    wire:click="removeItem('{{ $item['key'] }}')" 
                                    class="p-1 text-slate-400 hover:text-rose-500 transition" 
                                    title="Remove" aria-label="Remove"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right: Order Summary & Checkout -->
            <div class="lg:col-span-4 space-y-4">
                <!-- Free Delivery Progress (per seller: each seller ships and prices their own parcel) -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    @foreach ($shippingByZone['inside_dhaka']['vendors'] as $vendor)
                        @php
                            $progressPercentage = $vendor['threshold'] > 0 ? min(100, (int) round(($vendor['subtotal'] / $vendor['threshold']) * 100)) : 100;
                        @endphp
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between gap-2 text-xs font-bold">
                                @if ($vendor['is_free'])
                                    <span class="text-emerald-700">Free delivery from {{ $vendor['store_name'] }}</span>
                                    <span class="text-emerald-600 font-mono">100%</span>
                                @else
                                    <span class="text-slate-700">Add ৳{{ number_format($vendor['remaining_for_free'], 0) }} more from {{ $vendor['store_name'] }} for free delivery</span>
                                    <span class="text-indigo-600 font-mono">{{ $progressPercentage }}%</span>
                                @endif
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                <div class="h-2 rounded-full transition-all duration-500 {{ $vendor['is_free'] ? 'bg-emerald-500' : 'bg-indigo-600' }}" style="width: {{ $progressPercentage }}%"></div>
                            </div>
                        </div>
                    @endforeach
                    <p class="text-[11px] text-slate-500">Each seller sends their own parcel. Delivery is free when your order from that seller reaches their free-delivery amount.</p>
                </div>

                <!-- Coupon Code Box -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    <h4 class="font-bold text-slate-900 text-xs uppercase tracking-wider flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Coupon
                    </h4>

                    @if ($appliedCoupon)
                        <div class="flex items-center justify-between p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs">
                            <div>
                                <span class="font-mono font-black tracking-wider uppercase">{{ $appliedCoupon['code'] }}</span>
                                <span class="text-[11px] text-emerald-700 block font-medium">Applied: ৳{{ number_format($discountAmount, 2) }} discount</span>
                            </div>
                            <button 
                                wire:click="removeCoupon" 
                                class="px-2.5 py-1 text-xs font-bold text-rose-600 hover:text-rose-800 bg-white rounded-lg border border-rose-200 shadow-2xs"
                            >
                                Remove
                            </button>
                        </div>
                    @else
                        <form wire:submit.prevent="applyCoupon" class="flex gap-2">
                            <input 
                                type="text" 
                                wire:model="couponCode" 
                                placeholder="Coupon code" 
                                class="flex-1 uppercase text-xs py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono"
                            />
                            <button 
                                type="submit" 
                                class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer"
                            >
                                Apply
                            </button>
                        </form>
                    @endif
                </div>

                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                    <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3">Summary</h3>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between text-slate-600">
                            <span>Items</span>
                            <span class="font-bold text-slate-900">{{ $itemCount }}</span>
                        </div>
                        <div class="flex justify-between text-slate-600">
                            <span>Subtotal</span>
                            <span class="font-bold text-slate-900">৳{{ number_format($subtotal, 2) }}</span>
                        </div>
                        @if ($discountAmount > 0)
                            <div class="flex justify-between text-emerald-700 font-semibold">
                                <span>Coupon ({{ $appliedCoupon['code'] ?? '' }})</span>
                                <span class="font-bold font-mono">-৳{{ number_format($discountAmount, 2) }}</span>
                            </div>
                        @endif
                        <div class="text-slate-600 space-y-1">
                            <span>Delivery</span>
                            @foreach ($shippingByZone as $zone => $quote)
                                <div class="flex justify-between pl-2">
                                    <span class="text-slate-500">{{ \App\Services\Logistics\ShippingCalculatorService::ZONE_LABELS[$zone] }}</span>
                                    <span class="font-semibold text-slate-800">
                                        @if ($quote['total_shipping'] <= 0)
                                            <strong class="text-emerald-600">Free</strong>
                                        @else
                                            ৳{{ number_format($quote['total_shipping'], 0) }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3 space-y-1">
                        <div class="flex justify-between items-baseline">
                            <span class="text-sm font-bold text-slate-900">Estimated total</span>
                            <span class="text-xl font-black text-indigo-600">৳{{ number_format($estimatedTotal, 2) }}</span>
                        </div>
                        <p class="text-[11px] text-slate-500">Includes Inside Dhaka delivery. You will see the exact fee for your area at checkout.</p>
                    </div>

                    <div class="space-y-2 pt-2">
                        <a 
                            href="{{ route('checkout.index') }}" 
                            class="block w-full py-3.5 px-4 rounded-xl text-center font-bold text-sm bg-indigo-600 hover:bg-indigo-700 text-white transition shadow-sm"
                        >
                            Go to checkout &rarr;
                        </a>
                        <a 
                            href="{{ route('catalog.index') }}" 
                            class="block w-full py-2.5 px-4 rounded-xl text-center font-bold text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        >
                            Continue shopping
                        </a>
                    </div>
                </div>

            </div>
        </div>
    @endif
</div>
