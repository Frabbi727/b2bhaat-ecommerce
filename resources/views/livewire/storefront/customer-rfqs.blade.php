<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <!-- Header -->
    <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">My Quotations & RFQs</h1>
            <p class="text-xs text-slate-500 mt-1">
                Wholesale bulk pricing inquiries & formal factory quotations.
            </p>
        </div>

        <a href="{{ route('catalog.index') }}" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-xs">
            Browse Wholesale Catalog &rarr;
        </a>
    </div>

    <!-- Flash message -->
    @if (session()->has('status'))
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <!-- Filter Tabs with Counts -->
    <div class="flex flex-wrap items-center gap-2 bg-white p-2 rounded-2xl border border-slate-200 shadow-xs text-xs font-semibold">
        <button 
            type="button" 
            wire:click="setTab('all')"
            class="px-3.5 py-2 rounded-xl transition cursor-pointer flex items-center gap-2 {{ $tab === 'all' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
        >
            <span>All Inquiries</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'all' ? 'bg-indigo-700 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $counts['all'] }}</span>
        </button>

        <button 
            type="button" 
            wire:click="setTab('quoted')"
            class="px-3.5 py-2 rounded-xl transition cursor-pointer flex items-center gap-2 {{ $tab === 'quoted' ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
        >
            <span>Active Quotes</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'quoted' ? 'bg-emerald-700 text-white' : 'bg-emerald-100 text-emerald-800' }}">{{ $counts['quoted'] }}</span>
        </button>

        <button 
            type="button" 
            wire:click="setTab('pending')"
            class="px-3.5 py-2 rounded-xl transition cursor-pointer flex items-center gap-2 {{ $tab === 'pending' ? 'bg-amber-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
        >
            <span>Awaiting Factory</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'pending' ? 'bg-amber-700 text-white' : 'bg-amber-100 text-amber-800' }}">{{ $counts['pending'] }}</span>
        </button>

        <button 
            type="button" 
            wire:click="setTab('accepted')"
            class="px-3.5 py-2 rounded-xl transition cursor-pointer flex items-center gap-2 {{ $tab === 'accepted' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
        >
            <span>Accepted & Ordered</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'accepted' ? 'bg-indigo-700 text-white' : 'bg-slate-200 text-slate-700' }}">{{ $counts['accepted'] }}</span>
        </button>

        <button 
            type="button" 
            wire:click="setTab('declined')"
            class="px-3.5 py-2 rounded-xl transition cursor-pointer flex items-center gap-2 {{ $tab === 'declined' ? 'bg-rose-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
        >
            <span>Declined / Expired</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $tab === 'declined' ? 'bg-rose-700 text-white' : 'bg-rose-100 text-rose-800' }}">{{ $counts['declined'] }}</span>
        </button>
    </div>

    <!-- RFQ Cards List -->
    <div class="space-y-4">
        @forelse ($rfqs as $rfq)
            @php
                $isExpired = $rfq->isExpired();
                $isQuotedActive = $rfq->status === 'quoted' && ! $isExpired;
            @endphp
            <div class="bg-white rounded-2xl border border-slate-200 shadow-xs hover:border-slate-300 transition overflow-hidden">
                <div class="p-5 sm:p-6 space-y-4">
                    <!-- Top Bar: Ref & Status -->
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs font-bold text-slate-500">
                                #QT-RFQ-{{ str_pad((string) $rfq->id, 6, '0', STR_PAD_LEFT) }}
                            </span>
                            @if ($rfq->company_name)
                                <span class="text-xs text-slate-400">•</span>
                                <span class="text-xs font-bold text-slate-700">{{ $rfq->company_name }}</span>
                            @endif
                            <span class="text-xs text-slate-400">•</span>
                            <span class="text-xs text-slate-500">
                                {{ $rfq->created_at->format('d M Y') }}
                            </span>
                        </div>

                        <div>
                            @if ($isExpired)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800">
                                    Expired
                                </span>
                            @elseif ($rfq->status === 'quoted')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                                    Quoted
                                </span>
                            @elseif ($rfq->status === 'pending')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                    Pending Factory Review
                                </span>
                            @elseif ($rfq->status === 'accepted')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800">
                                    Accepted
                                </span>
                            @elseif ($rfq->status === 'rejected')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800">
                                    Declined
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-800">
                                    {{ ucfirst($rfq->status) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Main Grid: Product & Supplier & Rates -->
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                        <!-- Product Info (col 6) -->
                        <div class="md:col-span-5 flex items-start gap-3">
                            <div class="w-16 h-16 rounded-xl bg-slate-100 border border-slate-200 shrink-0 overflow-hidden flex items-center justify-center">
                                @if ($rfq->product?->featured_image_url)
                                    <img src="{{ $rfq->product->featured_image_url }}" alt="{{ $rfq->product?->title }}" class="w-full h-full object-cover">
                                @else
                                    <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                @endif
                            </div>

                            <div class="space-y-1">
                                @if ($rfq->product)
                                    <a href="{{ route('catalog.show', $rfq->product->slug) }}" class="text-sm font-bold text-slate-900 hover:text-indigo-600 transition line-clamp-2">
                                        {{ $rfq->product->title }}
                                    </a>
                                @else
                                    <div class="text-sm font-bold text-slate-900">Custom Inquiry (Product Archival)</div>
                                @endif

                                <div class="text-xs text-slate-500">
                                    Factory: <span class="font-semibold text-slate-700">{{ $rfq->seller?->sellerProfile?->store_name ?? $rfq->seller?->name ?? 'Direct Factory' }}</span>
                                </div>
                                <div class="text-xs text-slate-500">
                                    Destination: <span class="font-medium text-slate-700">{{ $rfq->destination_district }} District</span>
                                </div>
                            </div>
                        </div>

                        <!-- Quantity & Pricing (col 4) -->
                        <div class="md:col-span-4 bg-slate-50 rounded-xl p-3 border border-slate-100 space-y-2 text-xs">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Target Qty:</span>
                                <span class="font-bold font-mono text-slate-900">{{ number_format($rfq->target_quantity) }} {{ $rfq->product?->unit?->symbol ?? 'pcs' }}</span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Target Price:</span>
                                <span class="font-mono text-slate-700">৳{{ number_format((float) $rfq->target_unit_price, 2) }}</span>
                            </div>

                            @if ($rfq->seller_quoted_price)
                                <div class="flex justify-between items-center pt-1 border-t border-slate-200">
                                    <span class="font-bold text-emerald-700">Factory Quoted Rate:</span>
                                    <span class="font-black font-mono text-emerald-700 text-sm">৳{{ number_format((float) $rfq->seller_quoted_price, 2) }}</span>
                                </div>
                                <div class="flex justify-between items-center text-[11px] text-slate-500">
                                    <span>Total Lot Value:</span>
                                    <span class="font-bold font-mono text-slate-800">৳{{ number_format((float) $rfq->seller_quoted_price * $rfq->target_quantity, 2) }}</span>
                                </div>
                            @endif
                        </div>

                        <!-- Logistics / Lead Time & Expiry (col 3) -->
                        <div class="md:col-span-3 space-y-2 text-xs">
                            <div>
                                <span class="text-slate-400 block text-[11px]">Estimated Lead Time</span>
                                <span class="font-semibold text-slate-800">
                                    @if ($rfq->dispatch_days)
                                        {{ $rfq->dispatch_days }} days
                                    @else
                                        Standard (5-7 days)
                                    @endif
                                </span>
                            </div>

                            @if ($rfq->valid_until)
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Quotation Validity</span>
                                    @if ($isExpired)
                                        <span class="font-semibold text-rose-600">
                                            Expired on {{ $rfq->valid_until->format('d M Y') }}
                                        </span>
                                    @else
                                        <span class="font-semibold text-emerald-700">
                                            Valid until {{ $rfq->valid_until->format('d M Y') }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Custom Specifications or Notes -->
                    @if ($rfq->specifications || $rfq->seller_notes || $rfq->rejection_reason)
                        <div class="pt-2 border-t border-slate-100 text-xs space-y-1.5 text-slate-600 bg-slate-50/50 p-3 rounded-xl">
                            @if ($rfq->specifications)
                                <div><strong class="text-slate-800">Inquiry Specifications:</strong> {{ $rfq->specifications }}</div>
                            @endif
                            @if ($rfq->seller_notes)
                                <div class="text-indigo-900"><strong class="text-indigo-800">Factory Quotation Notes:</strong> {{ $rfq->seller_notes }}</div>
                            @endif
                            @if ($rfq->rejection_reason)
                                <div class="text-rose-700"><strong class="text-rose-800">Rejection Note ({{ $rfq->rejected_by }}):</strong> {{ $rfq->rejection_reason }}</div>
                            @endif
                        </div>
                    @endif

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100">
                        <div>
                            <a 
                                href="{{ route('rfq.quotation.print', $rfq->id) }}" 
                                target="_blank"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-semibold text-xs transition"
                            >
                                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span>View Quotation Sheet</span>
                            </a>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($isQuotedActive)
                                <button 
                                    type="button" 
                                    wire:click="confirmDecline({{ $rfq->id }})"
                                    class="px-3 py-1.5 rounded-xl border border-rose-200 text-rose-700 hover:bg-rose-50 font-semibold text-xs transition cursor-pointer"
                                >
                                    Decline Quote
                                </button>

                                <form action="{{ route('rfq.quotation.accept', $rfq->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button 
                                        type="submit" 
                                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer"
                                    >
                                        <span>✓ Accept & Order</span>
                                        <span class="font-mono font-semibold">(৳{{ number_format((float) $rfq->seller_quoted_price, 2) }}/u)</span>
                                    </button>
                                </form>
                            @elseif ($isExpired)
                                <button disabled class="px-4 py-2 rounded-xl bg-slate-100 text-slate-400 font-bold text-xs cursor-not-allowed">
                                    Quotation Expired
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center space-y-3">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="text-sm font-bold text-slate-800">No quotation requests found</h3>
                <p class="text-xs text-slate-500 max-w-sm mx-auto">
                    @if ($tab === 'all')
                        You haven't requested any custom wholesale quotations yet. Visit any wholesale product page and submit a custom RFQ!
                    @else
                        No requests currently match the "{{ ucfirst($tab) }}" filter tab.
                    @endif
                </p>
                <div class="pt-2">
                    <a href="{{ route('catalog.index') }}" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition">
                        Explore Wholesale Catalog
                    </a>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if ($rfqs->hasPages())
        <div class="pt-4">
            {{ $rfqs->links() }}
        </div>
    @endif

    <!-- Decline Confirmation Modal -->
    @if ($declineRfqId)
        <div 
            x-data
            @keydown.escape.window="$wire.closeDeclineModal()"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div 
                @click.outside="$wire.closeDeclineModal()"
                class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 border border-slate-200"
            >
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">Decline Quotation #QT-RFQ-{{ $declineRfqId }}</h3>
                    <button type="button" wire:click="closeDeclineModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <p class="text-xs text-slate-600">
                    Are you sure you want to decline this quotation from the manufacturer? Once declined, this quotation can no longer be accepted.
                </p>

                <div class="space-y-1.5">
                    <label for="declineReason" class="block text-xs font-semibold text-slate-700">Reason for declining (optional):</label>
                    <textarea 
                        id="declineReason"
                        wire:model="declineReason" 
                        rows="3" 
                        placeholder="e.g. Quoted rate is above our budget, lead time too long, or sourced elsewhere."
                        class="w-full text-xs rounded-xl border border-slate-300 p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    ></textarea>
                    @error('declineReason')
                        <span class="text-xs text-rose-600">{{ $message }}</span>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <button 
                        type="button" 
                        wire:click="closeDeclineModal"
                        class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 font-semibold text-xs transition cursor-pointer"
                    >
                        Cancel
                    </button>

                    <button 
                        type="button" 
                        wire:click="submitDecline"
                        class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition shadow-xs cursor-pointer"
                    >
                        Confirm Decline
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
