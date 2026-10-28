<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="text-center space-y-2 max-w-xl mx-auto">
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Public Courier & Logistics Tracking</h1>
        <p class="text-xs text-slate-500 leading-relaxed">
            Track multi-vendor shipments, courier assignments (Steadfast, Pathao, RedX), and package milestones in real time.
        </p>
    </div>

    <!-- Search Form -->
    <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs max-w-2xl mx-auto">
        <form wire:submit="searchOrder" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-6">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Order # or Courier Tracking # *</label>
                <input 
                    type="text" 
                    wire:model="orderNumber" 
                    placeholder="e.g. ORD-XXXXXX or Courier Tracking #" 
                    class="w-full py-2 px-3 border border-slate-300 rounded-xl text-xs font-mono uppercase focus:ring-2 focus:ring-indigo-500"
                />
            </div>

            <div class="sm:col-span-4">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Phone Number</label>
                <input 
                    type="text" 
                    wire:model="phone" 
                    placeholder="e.g. +88017..." 
                    class="w-full py-2 px-3 border border-slate-300 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500"
                />
            </div>

            <div class="sm:col-span-2 flex items-end">
                <button 
                    type="submit" 
                    class="w-full py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs cursor-pointer"
                >
                    Track
                </button>
            </div>
        </form>

        @if ($searchError)
            <div class="mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium">
                {{ $searchError }}
            </div>
        @endif
    </div>

    <!-- Order & Sub-Orders Display -->
    @if ($order)
        <div class="space-y-6">
            <!-- Master Order Summary Card -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <span class="text-xs text-slate-400 font-mono">Master Order</span>
                        <div class="text-xl font-black text-slate-900 font-mono">{{ $order->order_number }}</div>
                    </div>

                    <div class="flex items-center gap-3">
                        <a 
                            href="{{ route('order.invoice', $order->order_number) }}" 
                            target="_blank"
                            class="px-3 py-1 rounded-lg border border-slate-300 hover:border-slate-400 bg-white text-xs font-bold text-slate-700 flex items-center gap-1 shadow-2xs transition"
                        >
                            <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Print Tax Invoice & Challan
                        </a>

                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->orderStatus?->badge_color }}20; color: {{ $order->orderStatus?->badge_color }};">
                            {{ $order->orderStatus?->name }}
                        </span>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->paymentStatus?->badge_color }}20; color: {{ $order->paymentStatus?->badge_color }};">
                            Payment: {{ $order->paymentStatus?->name }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs text-slate-600">
                    <div>
                        <span class="text-slate-400 block">Placed On:</span>
                        <strong class="text-slate-800">{{ $order->created_at->format('M d, Y h:i A') }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Payment Method:</span>
                        <strong class="text-slate-800">{{ $order->paymentMethod?->name }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Total Amount:</span>
                        <strong class="text-slate-900 text-sm">৳{{ number_format($order->total_amount, 2) }}</strong>
                    </div>
                </div>
            </div>

            <!-- Sub-Orders / Packages Breakdown -->
            <div class="space-y-4">
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                    Vendor Consignment Packages ({{ $order->subOrders->count() }})
                </h3>

                @foreach ($order->subOrders as $subOrder)
                    <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-3">
                            <div>
                                <span class="text-[10px] font-mono text-slate-400">Package Consignment #</span>
                                <h4 class="text-base font-black text-slate-900 font-mono">{{ $subOrder->sub_order_number }}</h4>
                                <div class="text-xs text-slate-600 mt-0.5">
                                    Dispatched by: <strong class="text-indigo-600">{{ $subOrder->seller?->sellerProfile?->store_name }}</strong>
                                </div>
                            </div>

                            <div class="text-right space-y-1">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold" style="background-color: {{ $subOrder->fulfillmentStatus?->badge_color }}20; color: {{ $subOrder->fulfillmentStatus?->badge_color }};">
                                    {{ $subOrder->fulfillmentStatus?->name }}
                                </span>
                                @if ($subOrder->tracking_number)
                                    <div class="text-xs font-mono text-slate-700">
                                        {{ $subOrder->courier_name ?? 'Courier' }}: <strong>{{ $subOrder->tracking_number }}</strong>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Items in Package -->
                        <div class="space-y-1.5 text-xs">
                            <span class="text-slate-400 font-semibold uppercase text-[10px]">Package Contents:</span>
                            <div class="divide-y divide-slate-100 bg-slate-50 rounded-xl p-3 border border-slate-100">
                                @foreach ($subOrder->items as $item)
                                    <div class="py-1.5 flex justify-between items-center">
                                        <div>
                                            <span class="font-semibold text-slate-800">{{ $item->product?->title }}</span>
                                            <span class="text-slate-500">× {{ $item->quantity }}</span>
                                        </div>
                                        <span class="font-mono font-bold text-slate-700">৳{{ number_format($item->total_price, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- Courier Milestone Timeline -->
                        <div class="space-y-3 pt-2">
                            <h5 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Milestone Timeline</h5>

                            @if ($subOrder->trackingLogs->isNotEmpty())
                                <ol class="relative border-l border-slate-200 ml-3 space-y-4">
                                    @foreach ($subOrder->trackingLogs as $log)
                                        <li class="mb-4 ml-4">
                                            <div class="absolute w-2.5 h-2.5 bg-indigo-600 rounded-full mt-1 -left-1.25 border border-white"></div>
                                            <time class="mb-1 text-[11px] font-normal leading-none text-slate-400">
                                                {{ $log->logged_at ? $log->logged_at->format('M d, Y h:i A') : $log->created_at->format('M d, Y h:i A') }}
                                                @if ($log->hub_location) • Hub: {{ $log->hub_location }} @endif
                                            </time>
                                            <h6 class="text-xs font-bold text-slate-900">{{ $log->title }}</h6>
                                            @if ($log->description)
                                                <p class="text-xs text-slate-600 mt-0.5">{{ $log->description }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs text-slate-500 italic">
                                    Package is queued at vendor fulfillment hub. Courier tracking milestones will populate upon consignment handover.
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
