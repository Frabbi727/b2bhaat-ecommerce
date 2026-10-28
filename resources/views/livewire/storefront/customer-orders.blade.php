<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">My Order History & Receipts</h1>
            <p class="text-xs text-slate-500 mt-1">
                Account: <strong class="text-slate-800">{{ $user->name }}</strong> ({{ $user->email }}) • Role: <span class="capitalize font-semibold text-indigo-600">{{ str_replace('_', ' ', $user->role?->name ?? 'Buyer') }}</span>
            </p>
        </div>

        <a href="{{ route('catalog.index') }}" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs">
            Continue Sourcing & Shopping &rarr;
        </a>
    </div>

    @if ($orders->isEmpty())
        <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-4">
            <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800">You haven't placed any orders yet</h3>
            <p class="text-xs text-slate-500 max-w-sm mx-auto">
                Explore our dual-channel catalog to source wholesale bulk products or order single retail units.
            </p>
            <a href="{{ route('catalog.index') }}" class="inline-block mt-2 px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition">
                Browse Marketplace Catalog
            </a>
        </div>
    @else
        <div class="space-y-6">
            @foreach ($orders as $order)
                <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 shadow-xs space-y-6">
                    <!-- Top Summary -->
                    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <span class="text-[10px] font-mono text-slate-400 uppercase tracking-wider">Master Order</span>
                            <div class="text-lg font-black text-slate-900 font-mono">{{ $order->order_number }}</div>
                            <div class="text-xs text-slate-500 mt-0.5">Placed on {{ $order->created_at->format('M d, Y h:i A') }}</div>
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
                                Tax Invoice & Challan
                            </a>

                            <a 
                                href="{{ route('tracking.show', ['order' => $order->order_number, 'phone' => $order->shipping_address['recipient_phone'] ?? '']) }}" 
                                class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-xs font-bold text-white flex items-center gap-1 transition shadow-2xs"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                Track Consignments
                            </a>
                        </div>
                    </div>

                    <!-- Vendor Packages -->
                    <div class="space-y-3">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Vendor Consignment Packages ({{ $order->subOrders->count() }})</span>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($order->subOrders as $subOrder)
                                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2 text-xs">
                                    <div class="flex justify-between items-baseline">
                                        <div class="font-bold text-slate-900">{{ $subOrder->seller?->sellerProfile?->store_name }}</div>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background-color: {{ $subOrder->fulfillmentStatus?->badge_color }}20; color: {{ $subOrder->fulfillmentStatus?->badge_color }};">
                                            {{ $subOrder->fulfillmentStatus?->name }}
                                        </span>
                                    </div>

                                    <div class="text-[11px] text-slate-500 font-mono">
                                        Consignment: <strong>{{ $subOrder->sub_order_number }}</strong>
                                        @if ($subOrder->tracking_number)
                                            • {{ $subOrder->courier_name }}: <span class="text-indigo-600 font-bold">{{ $subOrder->tracking_number }}</span>
                                        @endif
                                    </div>

                                    <div class="border-t border-slate-200/60 pt-2 space-y-1">
                                        @foreach ($subOrder->items as $item)
                                            <div class="flex justify-between text-slate-700 text-[11px]">
                                                <span>{{ $item->product?->title }} × {{ $item->quantity }}</span>
                                                <span class="font-bold font-mono">৳{{ number_format($item->total_price, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Financial Breakdown Footer -->
                    <div class="border-t border-slate-100 pt-3 flex flex-wrap justify-between items-center text-xs gap-4">
                        <div class="text-slate-500">
                            Payment Method: <strong class="text-slate-800">{{ $order->paymentMethod?->name }}</strong>
                            • Courier Freight: <strong class="text-slate-800">{{ (float)$order->shipping_amount > 0 ? '৳' . number_format($order->shipping_amount, 2) : 'Free Bulk Delivery' }}</strong>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-500 mr-2">Grand Total Paid:</span>
                            <span class="text-xl font-black text-indigo-600 font-mono">৳{{ number_format($order->total_amount, 2) }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    @endif
</div>
