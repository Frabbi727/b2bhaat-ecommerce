<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-8 pb-4 border-b border-slate-200">
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Checkout & Logistics Manifest</h1>
        <p class="text-xs text-slate-500 mt-1">Multi-vendor partition engine will automatically manifest individual courier packages per supplier.</p>
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
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3 flex items-center gap-2">
                    <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs">1</span>
                    Delivery & Consignment Address
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Recipient / Business Name *</label>
                        <input 
                            type="text" 
                            wire:model="recipientName" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('recipientName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Contact Phone Number *</label>
                        <input 
                            type="text" 
                            wire:model="recipientPhone" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('recipientPhone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block font-semibold text-slate-700 mb-1">Street / Warehouse Address *</label>
                        <textarea 
                            rows="2" 
                            wire:model="streetAddress" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        ></textarea>
                        @error('streetAddress') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2 space-y-1.5 pt-1">
                        <label class="block font-semibold text-slate-700">Delivery Region / Courier Zone *</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-center justify-between p-3 rounded-xl border cursor-pointer transition {{ $deliveryZone === 'inside_dhaka' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:bg-slate-50' }}">
                                <div class="flex items-center gap-2">
                                    <input type="radio" wire:model.live="deliveryZone" value="inside_dhaka" class="text-indigo-600 focus:ring-indigo-500"/>
                                    <div>
                                        <div class="font-bold text-slate-900">Inside Dhaka</div>
                                        <div class="text-[10px] text-slate-500">24-48 Hours Express Delivery</div>
                                    </div>
                                </div>
                                <span class="font-bold text-indigo-600 font-mono">
                                    {{ $subtotal >= 10000 ? 'Free' : '৳70' }}
                                </span>
                            </label>

                            <label class="flex items-center justify-between p-3 rounded-xl border cursor-pointer transition {{ $deliveryZone === 'outside_dhaka' ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:bg-slate-50' }}">
                                <div class="flex items-center gap-2">
                                    <input type="radio" wire:model.live="deliveryZone" value="outside_dhaka" class="text-indigo-600 focus:ring-indigo-500"/>
                                    <div>
                                        <div class="font-bold text-slate-900">Outside Dhaka (All Districts)</div>
                                        <div class="text-[10px] text-slate-500">48-72 Hours Courier Delivery</div>
                                    </div>
                                </div>
                                <span class="font-bold text-indigo-600 font-mono">
                                    {{ $subtotal >= 10000 ? 'Free' : '৳130' }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">District / City *</label>
                        <input 
                            type="text" 
                            wire:model="city" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('city') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Postal Code *</label>
                        <input 
                            type="text" 
                            wire:model="postalCode" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('postalCode') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3 flex items-center gap-2">
                    <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs">2</span>
                    Payment & Escrow Channel
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
                                    <div class="text-xs font-bold text-slate-900">{{ $pm->name }}</div>
                                    <div class="text-[11px] text-slate-500">
                                        @if ($pm->slug === 'mock_gateway')
                                            Immediate payment confirmation & escrow hold simulation
                                        @elseif ($pm->slug === 'cod')
                                            Pay upon physical package inspection & delivery
                                        @else
                                            Secure automated online checkout
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                                {{ $pm->slug }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Right: Order Manifest & Confirmation -->
        <div class="lg:col-span-5 space-y-4">
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <h3 class="font-bold text-slate-900 text-sm border-b border-slate-100 pb-3">Package Items Overview</h3>

                <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto space-y-2 pr-1">
                    @foreach ($items as $item)
                        <div class="py-2 flex items-center justify-between text-xs gap-3">
                            <div>
                                <div class="font-semibold text-slate-800">{{ $item['product_title'] }}</div>
                                <div class="text-[11px] text-slate-500 font-mono">{{ $item['variation_sku'] }} (Qty: {{ $item['quantity'] }})</div>
                                <div class="text-[10px] text-indigo-600">Vendor: {{ $item['seller_name'] }}</div>
                            </div>
                            <div class="font-extrabold text-slate-900">
                                ৳{{ number_format($item['unit_price'] * $item['quantity'], 2) }}
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="border-t border-slate-100 pt-3 space-y-1.5 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Items Subtotal:</span>
                        <span class="font-bold text-slate-900">৳{{ number_format($subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Courier / Shipping:</span>
                        @if ($shippingFee == 0)
                            <span class="text-emerald-600 font-bold">Free Bulk Delivery</span>
                        @else
                            <span class="font-bold text-slate-800 font-mono">৳{{ number_format($shippingFee, 2) }}</span>
                        @endif
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Platform Commission:</span>
                        <span class="text-slate-500 italic">Deducted from vendor payout</span>
                    </div>
                    <div class="border-t border-slate-200 pt-2 flex justify-between items-baseline">
                        <span class="text-sm font-bold text-slate-900">Grand Total:</span>
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
                    <span wire:loading.remove>Confirm Order & Lock Stock</span>
                    <span wire:loading>Reserving Stock & Partitioning Orders...</span>
                </button>
            </div>
        </div>
    </form>
</div>
