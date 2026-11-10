<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">My orders</h1>
            <p class="text-xs text-slate-500 mt-1">
                Signed in as <strong class="text-slate-800">{{ $user->name }}</strong>
            </p>
        </div>

        <a href="{{ route('catalog.index') }}" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs">
            Continue shopping &rarr;
        </a>
    </div>

    <!-- Live Aggregated Metrics Bar (Zero Fake / Mock Numbers) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            </div>
            <div>
                <div class="text-2xl font-black text-slate-900">{{ $totalOrdersCount }}</div>
                <div class="text-xs text-slate-500 font-medium">Orders placed</div>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-2xl font-black text-slate-900">৳{{ number_format($totalSpentAmount, 2) }}</div>
                <div class="text-xs text-slate-500 font-medium">Paid</div>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            </div>
            <div>
                <div class="text-2xl font-black text-slate-900">{{ $inTransitCount }}</div>
                <div class="text-xs text-slate-500 font-medium">Parcels on the way</div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Bar -->
    <div class="flex flex-wrap items-center justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <!-- Status Tabs -->
        <div class="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
            <button 
                type="button" 
                wire:click="$set('statusFilter', 'all')"
                class="px-3 py-1.5 rounded-xl transition cursor-pointer {{ $statusFilter === 'all' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                All orders
            </button>
            <button 
                type="button" 
                wire:click="$set('statusFilter', 'confirmed')"
                class="px-3 py-1.5 rounded-xl transition cursor-pointer {{ $statusFilter === 'confirmed' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Confirmed
            </button>
            <button 
                type="button" 
                wire:click="$set('statusFilter', 'completed')"
                class="px-3 py-1.5 rounded-xl transition cursor-pointer {{ $statusFilter === 'completed' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Completed
            </button>
            <button 
                type="button" 
                wire:click="$set('statusFilter', 'cancelled')"
                class="px-3 py-1.5 rounded-xl transition cursor-pointer {{ $statusFilter === 'cancelled' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
            >
                Cancelled
            </button>
        </div>

        <!-- Search Input -->
        <div class="relative w-full sm:w-64">
            <input 
                type="text" 
                wire:model.live.debounce.300ms="searchQuery" 
                placeholder="Search order # or item..."
                class="w-full text-xs py-2 pl-8 pr-3 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500"
            />
            <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
    </div>

    @if ($orders->isEmpty())
        <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-4">
            <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800">No orders found</h3>
            <p class="text-xs text-slate-500 max-w-sm mx-auto">
                Try a different search or filter.
            </p>
        </div>
    @else
        <div class="space-y-6">
            @foreach ($orders as $order)
                <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-xs space-y-6">
                    <!-- Top Summary -->
                    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <span class="text-[10px] font-mono text-slate-400 uppercase tracking-wider">Order</span>
                            <div class="text-lg font-black text-slate-900 font-mono">{{ $order->order_number }}</div>
                            <div class="text-xs text-slate-500 mt-0.5">Placed on {{ $order->created_at->format('d M Y, g:i A') }}</div>
                        </div>

                        <!-- Actions & Badges -->
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->orderStatus?->badge_color }}20; color: {{ $order->orderStatus?->badge_color }};">
                                {{ $order->orderStatus?->name }}
                            </span>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->paymentStatus?->badge_color }}20; color: {{ $order->paymentStatus?->badge_color }};">
                                Payment: {{ $order->paymentStatus?->name }}
                            </span>

                            <a 
                                href="{{ route('order.invoice', $order->order_number) }}" 
                                target="_blank"
                                class="px-3 py-1.5 rounded-xl border border-slate-200 hover:border-slate-300 bg-slate-50 hover:bg-slate-100 text-xs font-bold text-slate-700 flex items-center gap-1 transition shadow-2xs"
                            >
                                <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                </svg>
                                Invoice
                            </a>

                            <a 
                                href="{{ route('tracking.show', ['order' => $order->order_number]) }}" 
                                class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold flex items-center gap-1 transition shadow-2xs"
                            >
                                Track order &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Parcels, one per seller -->
                    <div class="space-y-4">
                        <div class="text-xs font-bold text-slate-700 uppercase tracking-wider">
                            {{ $order->subOrders->count() === 1 ? '1 parcel' : $order->subOrders->count().' parcels' }}
                        </div>

                        <div class="grid grid-cols-1 gap-4">
                            @foreach ($order->subOrders as $sub)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50/50 p-4 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/60 pb-2 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                                            <span class="font-bold text-slate-800">{{ $sub->seller?->sellerProfile?->store_name ?? 'Seller' }}</span>
                                            <span class="font-mono text-slate-400 text-[11px]">({{ $sub->sub_order_number }})</span>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" style="background-color: {{ $sub->fulfillmentStatus?->badge_color }}20; color: {{ $sub->fulfillmentStatus?->badge_color }};">
                                                {{ $sub->fulfillmentStatus?->name }}
                                            </span>
                                            @if ($sub->tracking_number)
                                                <span class="font-mono text-[11px] text-slate-500 font-semibold">
                                                    {{ $sub->courier_name }}: {{ $sub->tracking_number }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Items in Sub-Order -->
                                    <div class="divide-y divide-slate-100">
                                        @foreach ($sub->items as $item)
                                            <div class="py-2 flex items-center justify-between text-xs">
                                                <div class="flex items-center gap-3">
                                                    @if ($item->product?->primaryImage)
                                                        <img src="{{ $item->product->primaryImage->public_url }}" class="w-10 h-10 object-cover rounded-lg border border-slate-200 shrink-0"/>
                                                    @endif
                                                    <div>
                                                        <div class="font-bold text-slate-800 line-clamp-1">{{ $item->product?->title }}</div>
                                                        <div class="text-[11px] text-slate-400">Qty: {{ $item->quantity }} • ৳{{ number_format($item->unit_price, 2) }} each</div>
                                                    </div>
                                                </div>
                                                <div class="font-mono font-bold text-slate-900">
                                                    ৳{{ number_format($item->total_price, 2) }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($sub->is_self_delivered && $sub->handover_code && $sub->fulfillmentStatus?->slug !== 'delivered')
                                        <div class="mt-2 p-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 flex items-center justify-between text-xs">
                                            <span class="text-[11px] font-semibold">Your delivery code (give it to the delivery person after you check the parcel):</span>
                                            <span class="font-mono font-black text-xs px-2 py-0.5 rounded bg-amber-200/90 text-amber-950 tracking-wider">
                                                {{ $sub->handover_code }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Financial Breakdown Footer -->
                    <div class="border-t border-slate-100 pt-4 flex flex-wrap justify-between items-center text-xs">
                        <div class="text-slate-500 text-[11px]">
                            Delivery address: <strong class="text-slate-700">{{ $order->shipping_address['street_address'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}</strong>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-500">Total</span>
                            <span class="text-base font-black text-indigo-600 font-mono ml-1">৳{{ number_format($order->total_amount, 2) }}</span>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="pt-4">
                {{ $orders->links() }}
            </div>
        </div>
    @endif
</div>
