<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-200">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Shopping Cart</h1>
            <p class="text-xs text-slate-500 mt-1">Review items, adjust quantities, and verify Minimum Order Quantities before checkout.</p>
        </div>

        @if (! empty($items))
            <button 
                wire:click="clearCart" 
                class="text-xs text-rose-600 hover:text-rose-700 font-semibold underline"
            >
                Empty Cart
            </button>
        @endif
    </div>

    @if ($errorMessage)
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    @if (empty($items))
        <div class="py-16 text-center space-y-4 bg-white rounded-2xl border border-slate-200 p-8">
            <div class="w-16 h-16 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800">Your cart is currently empty</h3>
            <p class="text-xs text-slate-500 max-w-sm mx-auto">Browse our dual-channel catalog for factory-direct wholesale lots or retail single items.</p>
            <a href="{{ route('catalog.index') }}" class="inline-block mt-2 px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition shadow-xs">
                Browse Marketplace
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Left: Cart Items List -->
            <div class="lg:col-span-8 space-y-4">
                @foreach ($items as $item)
                    @php
                        $lineTotal = $item['unit_price'] * $item['quantity'];
                        $isWholesale = $item['sales_channel_slug'] === 'wholesale';
                    @endphp
                    <div class="p-4 rounded-xl bg-white border border-slate-200 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ $isWholesale ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-100 text-indigo-800' }}">
                                    {{ $isWholesale ? 'Wholesale B2B' : 'Retail B2C' }}
                                </span>
                                <span class="text-xs text-slate-400 font-mono">{{ $item['variation_sku'] }}</span>
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
                                Seller: <strong class="text-slate-700">{{ $item['seller_name'] }}</strong>
                            </div>
                        </div>

                        <!-- Quantity and Line Pricing -->
                        <div class="flex items-center justify-between sm:justify-end gap-6 w-full sm:w-auto">
                            <div>
                                <span class="text-xs text-slate-400 block sm:text-right">Unit Price</span>
                                <span class="text-xs font-bold text-slate-700">৳{{ number_format($item['unit_price'], 2) }}</span>
                            </div>

                            <!-- Quantity adjustment -->
                            <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden bg-slate-50">
                                <button 
                                    wire:click="updateQuantity('{{ $item['key'] }}', {{ $item['quantity'] - 1 }})" 
                                    class="px-2.5 py-1 text-slate-600 hover:bg-slate-200 font-bold text-xs"
                                >
                                    -
                                </button>
                                <span class="px-3 py-1 font-bold text-xs text-slate-800 min-w-8 text-center bg-white">
                                    {{ $item['quantity'] }}
                                </span>
                                <button 
                                    wire:click="updateQuantity('{{ $item['key'] }}', {{ $item['quantity'] + 1 }})" 
                                    class="px-2.5 py-1 text-slate-600 hover:bg-slate-200 font-bold text-xs"
                                >
                                    +
                                </button>
                            </div>

                            <div class="text-right min-w-24">
                                <span class="text-xs text-slate-400 block">Total</span>
                                <span class="text-sm font-extrabold text-slate-900">৳{{ number_format($lineTotal, 2) }}</span>
                            </div>

                            <button 
                                wire:click="removeItem('{{ $item['key'] }}')" 
                                class="text-slate-400 hover:text-rose-500 transition" 
                                title="Remove Item"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Right: Order Summary & Checkout -->
            <div class="lg:col-span-4 space-y-4">
                <!-- Free Shipping Progress -->
                @php
                    $freeShippingThreshold = 10000.00;
                    $progressPercentage = min(100, round(($subtotal / $freeShippingThreshold) * 100));
                @endphp
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-2">
                    <div class="flex items-center justify-between text-xs font-bold">
                        @if ($subtotal >= $freeShippingThreshold)
                            <span class="text-emerald-700 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Free Nationwide Delivery Unlocked!
                            </span>
                            <span class="text-emerald-600 font-mono">100%</span>
                        @else
                            <span class="text-slate-700">Add ৳{{ number_format($freeShippingThreshold - $subtotal, 2) }} more for Free Shipping</span>
                            <span class="text-indigo-600 font-mono">{{ $progressPercentage }}%</span>
                        @endif
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div class="h-2 rounded-full transition-all duration-500 {{ $subtotal >= $freeShippingThreshold ? 'bg-emerald-500' : 'bg-indigo-600' }}" style="width: {{ $progressPercentage }}%"></div>
                    </div>
                    <p class="text-[10px] text-slate-400">All wholesale & retail orders above ৳10,000 qualify for free courier shipping across all 64 districts.</p>
                </div>

                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                    <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3">Order Summary</h3>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between text-slate-600">
                            <span>Total Items:</span>
                            <span class="font-bold text-slate-900">{{ $itemCount }}</span>
                        </div>
                        <div class="flex justify-between text-slate-600">
                            <span>Subtotal:</span>
                            <span class="font-bold text-slate-900">৳{{ number_format($subtotal, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-slate-600">
                            <span>Shipping / Freight:</span>
                            <span class="text-slate-500">
                                @if ($subtotal >= $freeShippingThreshold)
                                    <strong class="text-emerald-600">FREE</strong>
                                @else
                                    ৳70 (Dhaka) / ৳130 (Outside)
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3 flex justify-between items-baseline">
                        <span class="text-sm font-bold text-slate-900">Estimated Total:</span>
                        <span class="text-xl font-black text-indigo-600">৳{{ number_format($subtotal, 2) }}</span>
                    </div>

                    <div class="space-y-2 pt-2">
                        <a 
                            href="{{ route('checkout.index') }}" 
                            class="block w-full py-3.5 px-4 rounded-xl text-center font-bold text-sm bg-indigo-600 hover:bg-indigo-700 text-white transition shadow-sm"
                        >
                            Proceed to Secure Checkout &rarr;
                        </a>
                        <a 
                            href="{{ route('catalog.index') }}" 
                            class="block w-full py-2.5 px-4 rounded-xl text-center font-bold text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        >
                            Continue Shopping / Sourcing
                        </a>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-500 space-y-2">
                    <div class="font-semibold text-slate-700">Concurrency-Safe Ordering:</div>
                    <p class="leading-relaxed">
                        Inventory is locked with pessimistic row locking (`SELECT FOR UPDATE`) during checkout to prevent duplicate sales across retail & wholesale buyers.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
