<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="text-center space-y-2 max-w-xl mx-auto">
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Track your order</h1>
        <p class="text-xs text-slate-500 leading-relaxed">
            Enter your order number to see where each parcel is.
        </p>
    </div>

    <!-- Search Form -->
    <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs max-w-2xl mx-auto">
        <form wire:submit="searchOrder" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-6">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Order number or courier tracking number *</label>
                <input 
                    type="text" 
                    wire:model="orderNumber" 
                    placeholder="e.g. ORD-XXXXXX" 
                    class="w-full py-2 px-3 border border-slate-300 rounded-xl text-xs font-mono uppercase focus:ring-2 focus:ring-indigo-500"
                />
            </div>

            <div class="sm:col-span-4">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Mobile number used for the order</label>
                <input 
                    type="text" 
                    wire:model="phone" 
                    placeholder="e.g. 01712345678" 
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
            @if ($actionFeedback)
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>{{ $actionFeedback }}</span>
                </div>
            @endif

            @if ($actionError)
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ $actionError }}</span>
                </div>
            @endif

            <!-- Master Order Summary Card -->
            <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-100 pb-4">
                    <div>
                        <span class="text-xs text-slate-400 font-mono">Order</span>
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
                            Print invoice
                        </a>

                        @php
                            $canCancelOrder = $order->orderStatus?->slug !== 'cancelled' && 
                                $order->subOrders->every(fn($s) => in_array($s->fulfillmentStatus?->slug, ['unfulfilled', 'processing']));
                        @endphp

                        @if ($canCancelOrder)
                            <button 
                                type="button" 
                                wire:click="cancelEntireOrder"
                                wire:confirm="Cancel this whole order? This can't be undone."
                                class="px-3 py-1 rounded-lg border border-rose-200 hover:border-rose-300 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold transition cursor-pointer"
                            >
                                Cancel order
                            </button>
                        @endif

                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->orderStatus?->badge_color }}20; color: {{ $order->orderStatus?->badge_color }};">
                            {{ $order->orderStatus?->name }}
                        </span>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" style="background-color: {{ $order->paymentStatus?->badge_color }}20; color: {{ $order->paymentStatus?->badge_color }};">
                            Payment: {{ $order->paymentStatus?->name }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs text-slate-600">
                    <div>
                        <span class="text-slate-400 block">Placed on</span>
                        <strong class="text-slate-800">{{ $order->created_at->format('M d, Y h:i A') }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Payment</span>
                        <strong class="text-slate-800">{{ $order->paymentMethod?->name }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Delivery</span>
                        <strong class="text-slate-800">{{ $order->shipping_amount == 0 ? 'Free' : '৳' . number_format($order->shipping_amount, 2) }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Total</span>
                        <strong class="text-slate-900 text-sm">৳{{ number_format($order->total_amount, 2) }}</strong>
                        @if ($order->discount_amount > 0)
                            <span class="block text-[11px] text-emerald-600 font-semibold font-mono">(Saved ৳{{ number_format($order->discount_amount, 2) }})</span>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Sub-Orders / Packages Breakdown -->
            <div class="space-y-4">
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                    {{ $order->subOrders->count() === 1 ? '1 parcel' : $order->subOrders->count().' parcels' }}
                </h3>

                @foreach ($order->subOrders as $subOrder)
                    <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-3">
                            <div>
                                <span class="text-[10px] font-mono text-slate-400">Parcel</span>
                                <h4 class="text-base font-black text-slate-900 font-mono">{{ $subOrder->sub_order_number }}</h4>
                                <div class="text-xs text-slate-600 mt-0.5">
                                    Sent by <strong class="text-indigo-600">{{ $subOrder->seller?->sellerProfile?->store_name }}</strong>
                                </div>
                            </div>

                            <div class="text-right space-y-1">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold" style="background-color: {{ $subOrder->fulfillmentStatus?->badge_color }}20; color: {{ $subOrder->fulfillmentStatus?->badge_color }};">
                                    {{ $subOrder->fulfillmentStatus?->name }}
                                </span>
                                @if ($subOrder->tracking_number)
                                    <div class="text-xs font-mono text-slate-700">
                                        {{ $subOrder->courier_name ?? 'Courier' }}: 
                                        @if ($subOrder->tracking_url)
                                            <a href="{{ $subOrder->tracking_url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 underline font-bold inline-flex items-center gap-1" title="Open live courier tracking page">
                                                <span>{{ $subOrder->tracking_number }}</span>
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                            </a>
                                        @else
                                            <strong>{{ $subOrder->tracking_number }}</strong>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Self-Delivery Handover Code Banner (Decision D5) -->
                        @if ($subOrder->is_self_delivered)
                            <div class="p-4 rounded-xl border {{ $subOrder->fulfillmentStatus?->slug === 'delivered' ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-300 text-amber-950 shadow-xs' }}">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-2">
                                            <h5 class="font-bold text-sm">
                                                {{ $subOrder->fulfillmentStatus?->slug === 'delivered' ? 'Delivered by the seller' : 'The seller delivers this parcel' }}
                                            </h5>
                                        </div>
                                        <p class="text-xs text-slate-600">
                                            @if ($subOrder->fulfillmentStatus?->slug === 'delivered')
                                                Received on {{ $subOrder->handover_confirmed_at?->format('M d, Y h:i A') ?? 'delivery' }}.
                                            @else
                                                Check your parcel first, then give this 6-digit code to the delivery person. Don't share it before you have the parcel.
                                            @endif
                                        </p>
                                    </div>
                                    @if ($canSeeHandoverCode && $subOrder->handover_code && $subOrder->fulfillmentStatus?->slug !== 'delivered')
                                        <div class="text-center sm:text-right shrink-0">
                                            <span class="text-[10px] uppercase font-bold text-slate-500 block">Your delivery code</span>
                                            <div class="inline-block px-4 py-1.5 rounded-lg bg-amber-200/80 border border-amber-400 font-mono font-black text-xl text-amber-950 tracking-widest shadow-inner">
                                                {{ $subOrder->handover_code }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <!-- Items in Package -->
                        <div class="space-y-1.5 text-xs">
                            <span class="text-slate-400 font-semibold uppercase text-[10px]">Items</span>
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

                        <!-- Package Action Controls (Cancel / Return Dispute) -->
                        <div class="flex items-center justify-between text-xs pt-1">
                            <div>
                                @if ($subOrder->fulfillmentStatus?->slug === 'delivered')
                                    @php
                                        $pendingReturn = $subOrder->returnRequests->firstWhere('status', 'pending');
                                    @endphp
                                    @if ($pendingReturn)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold">
                                            Return request under review ({{ ucfirst($pendingReturn->requested_action) }})
                                        </span>
                                    @else
                                        <button 
                                            type="button" 
                                            wire:click="openReturnModal({{ $subOrder->id }})"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-700 font-bold text-xs transition cursor-pointer"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                                            Report a problem
                                        </button>
                                    @endif
                                @elseif ($subOrder->fulfillmentStatus?->slug === 'unfulfilled')
                                    <button 
                                        type="button" 
                                        wire:click="cancelSubOrderConsignment({{ $subOrder->id }})"
                                        wire:confirm="Cancel this parcel? This can't be undone."
                                        class="px-2.5 py-1 rounded-lg border border-rose-200 hover:border-rose-300 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[11px] transition cursor-pointer"
                                    >
                                        Cancel parcel
                                    </button>
                                @endif
                            </div>
                        </div>

                        <!-- Courier Milestone Timeline -->
                        <div class="space-y-3 pt-2">
                            <h5 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Progress</h5>

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
                                    The seller is preparing this parcel. Updates appear here once it is handed to the courier.
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Return / Dispute Request Modal -->
    @if ($showReturnModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-200 relative animate-in fade-in duration-200">
                <button 
                    wire:click="closeReturnModal" 
                    class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 cursor-pointer"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-rose-100 text-rose-800">
                        Report a problem
                    </span>
                    <h2 class="text-xl font-black text-slate-900 mt-1">Return or replace an item</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        You can report a problem within {{ $returnWindowHours }} hours of delivery.
                    </p>
                </div>

                <form wire:submit="submitReturnRequest" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">What went wrong? *</label>
                        <select 
                            wire:model="returnReason" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 bg-white"
                        >
                            <option value="defective_item">The item doesn't work</option>
                            <option value="damaged_goods">It arrived damaged</option>
                            <option value="incorrect_variation">Wrong item, colour or size</option>
                            <option value="not_as_described">Not as described</option>
                            <option value="other">Something else</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">What would you like? *</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 {{ $returnRequestedAction === 'refund' ? 'border-indigo-600 bg-indigo-50/50' : '' }}">
                                <input type="radio" value="refund" wire:model.live="returnRequestedAction" class="text-indigo-600 focus:ring-indigo-500"/>
                                <div>
                                    <strong class="block text-slate-900">Refund</strong>
                                    <span class="text-[10px] text-slate-500">Get your money back</span>
                                </div>
                            </label>

                            <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 {{ $returnRequestedAction === 'replacement' ? 'border-indigo-600 bg-indigo-50/50' : '' }}">
                                <input type="radio" value="replacement" wire:model.live="returnRequestedAction" class="text-indigo-600 focus:ring-indigo-500"/>
                                <div>
                                    <strong class="block text-slate-900">Replacement</strong>
                                    <span class="text-[10px] text-slate-500">Get a new one</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Tell us more *</label>
                        <textarea 
                            rows="3" 
                            wire:model="returnDescription" 
                            placeholder="What is wrong with the item?"
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        ></textarea>
                        @error('returnDescription') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-3">
                        <button 
                            type="button" 
                            wire:click="closeReturnModal" 
                            class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            wire:loading.attr="disabled"
                            class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer"
                        >
                            <svg wire:loading class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            <span>Send request</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
