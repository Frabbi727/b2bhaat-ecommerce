<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-slate-500 mb-6">
        <a href="{{ route('catalog.index') }}" class="hover:underline">Catalog</a>
        <span>/</span>
        <span>{{ $product->category?->name }}</span>
        <span>/</span>
        <span class="text-slate-800 font-semibold">{{ $product->title }}</span>
    </nav>

    <!-- Feedback Alerts -->
    @if ($feedbackMessage)
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <a href="{{ route('cart.index') }}" class="font-bold underline text-emerald-900">View Cart & Checkout &rarr;</a>
        </div>
    @endif

    @if ($errorMessage)
        <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
        <!-- Left: Product Gallery -->
        <div class="lg:col-span-5 space-y-4">
            <div class="aspect-4/3 rounded-2xl bg-white border border-slate-200 overflow-hidden shadow-xs">
                @php
                    $imgUrl = $product->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=800';
                @endphp
                <img src="{{ $imgUrl }}" alt="{{ $product->title }}" class="w-full h-full object-cover"/>
            </div>

            <div class="p-4 rounded-xl bg-slate-100 border border-slate-200 text-xs text-slate-600 space-y-1.5">
                <div class="flex justify-between items-center">
                    <span class="text-slate-500">Verified Seller:</span>
                    <a href="{{ route('store.show', $product->seller?->sellerProfile?->store_slug) }}" class="font-bold text-indigo-600 hover:text-indigo-800 underline">
                        {{ $product->seller?->sellerProfile?->store_name }} &rarr;
                    </a>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Sales Channel:</span>
                    <span class="font-semibold uppercase text-indigo-700">{{ $product->salesChannel?->name }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Standard Unit:</span>
                    <span class="font-medium text-slate-800">{{ $product->unit?->name }} ({{ $product->unit?->symbol }})</span>
                </div>
            </div>
        </div>

        <!-- Right: Purchase Controls & B2B Matrix -->
        <div class="lg:col-span-7 space-y-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-indigo-100 text-indigo-800">
                        {{ $product->brand?->name ?? 'Direct Factory' }}
                    </span>
                    @if ($product->salesChannel?->slug === 'both')
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800">
                            Hybrid B2B + B2C
                        </span>
                    @endif
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                    {{ $product->title }}
                </h1>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                    {{ $product->short_description }}
                </p>
            </div>

            <!-- Wholesale Buyer Experience: Interactive Bulk Variation Matrix -->
            @if ($isWholesaleUser)
                <div class="p-6 rounded-2xl bg-white border border-emerald-200 shadow-sm space-y-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-4">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-emerald-600">Wholesale Tier Pricing</span>
                            <div class="text-3xl font-black text-slate-900">
                                ৳{{ number_format($wholesalePrice?->unit_price ?? 0, 2) }}
                                <span class="text-sm font-normal text-slate-500">/ {{ $product->unit?->symbol }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs px-2.5 py-1 rounded bg-emerald-100 text-emerald-800 font-extrabold">
                                MOQ: {{ $wholesalePrice?->min_quantity ?? 1 }} {{ $product->unit?->symbol }}
                            </span>
                        </div>
                    </div>

                    <!-- Alpine.js Multi-Variation Bulk Matrix Table -->
                    <div 
                        x-data="{
                            moq: {{ $wholesalePrice?->min_quantity ?? 1 }},
                            unitPrice: {{ $wholesalePrice?->unit_price ?? 0 }},
                            quantities: @entangle('matrixQuantities'),
                            get totalUnits() {
                                return Object.values(this.quantities).reduce((acc, val) => acc + (parseInt(val) || 0), 0);
                            },
                            get grandTotal() {
                                return (this.totalUnits * this.unitPrice).toFixed(2);
                            },
                            get isMoqMet() {
                                return this.totalUnits >= this.moq;
                            }
                        }"
                        class="space-y-4"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <h3 class="font-bold text-slate-900">B2B Bulk Variation Matrix</h3>
                            <span class="text-slate-500">Enter order quantity across multiple variations</span>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-600 uppercase font-semibold border-b border-slate-200">
                                    <tr>
                                        <th class="py-2.5 px-3">SKU</th>
                                        <th class="py-2.5 px-3">Attributes</th>
                                        <th class="py-2.5 px-3">Stock Available</th>
                                        <th class="py-2.5 px-3 w-32 text-right">Order Units</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($product->variations as $var)
                                        @php
                                            $attrs = $var->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                                        @endphp
                                        <tr class="hover:bg-slate-50/80 transition">
                                            <td class="py-2.5 px-3 font-mono text-slate-700 font-semibold">{{ $var->sku }}</td>
                                            <td class="py-2.5 px-3 text-slate-800">{{ $attrs }}</td>
                                            <td class="py-2.5 px-3">
                                                <span class="px-2 py-0.5 rounded text-[11px] font-bold {{ $var->stock_quantity > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                                    {{ $var->stock_quantity }} {{ $product->unit?->symbol }}
                                                </span>
                                            </td>
                                            <td class="py-2.5 px-3 text-right">
                                                <input 
                                                    type="number" 
                                                    min="0" 
                                                    max="{{ $var->stock_quantity }}"
                                                    x-model.number="quantities[{{ $var->id }}]"
                                                    class="w-24 text-right py-1 px-2 border border-slate-300 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-emerald-500"
                                                />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Live Alpine Calculation Bar -->
                        <div class="p-4 rounded-xl bg-slate-900 text-white flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div class="text-xs text-slate-400">Total Bulk Quantity:</div>
                                <div class="text-xl font-black">
                                    <span x-text="totalUnits"></span> / <span x-text="moq"></span> {{ $product->unit?->symbol }}
                                </div>
                                <div class="text-xs mt-0.5">
                                    <template x-if="!isMoqMet">
                                        <span class="text-amber-400 font-medium">
                                            Need <span x-text="moq - totalUnits"></span> more units to satisfy MOQ!
                                        </span>
                                    </template>
                                    <template x-if="isMoqMet">
                                        <span class="text-emerald-400 font-medium">✓ Wholesale MOQ criteria satisfied</span>
                                    </template>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-xs text-slate-400">Bulk Subtotal:</div>
                                <div class="text-2xl font-black text-emerald-400">
                                    ৳<span x-text="grandTotal"></span>
                                </div>
                            </div>
                        </div>

                        <button 
                            type="button" 
                            wire:click="addBulkMatrixToCart"
                            x-bind:disabled="!isMoqMet"
                            class="w-full py-3.5 px-4 rounded-xl font-bold text-sm text-white transition shadow-sm flex items-center justify-center gap-2"
                            x-bind:class="isMoqMet ? 'bg-emerald-600 hover:bg-emerald-700 cursor-pointer' : 'bg-slate-300 cursor-not-allowed'"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            Add Complete Bulk Lot to Cart
                        </button>

                        <div class="pt-2 text-center">
                            <button 
                                type="button" 
                                wire:click="openRfqModal"
                                class="text-xs font-bold text-indigo-600 hover:text-indigo-800 underline flex items-center justify-center gap-1 mx-auto cursor-pointer"
                            >
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                Need 500+ units or custom factory branding? Request Custom RFQ &rarr;
                            </button>
                        </div>
                    </div>
                </div>

            @else
                <!-- Retail Buyer / Guest Experience -->
                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm space-y-6">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-4">
                        <div>
                            <span class="text-xs font-semibold text-slate-500 uppercase">Retail Price</span>
                            <div class="text-3xl font-black text-slate-900">
                                ৳{{ number_format($retailPrice?->unit_price ?? 0, 2) }}
                                <span class="text-sm font-normal text-slate-500">/ {{ $product->unit?->symbol }}</span>
                            </div>
                        </div>
                        <span class="text-xs px-2.5 py-1 rounded-full bg-slate-100 text-slate-700 font-semibold">
                            Single Units Allowed (MOQ: 1)
                        </span>
                    </div>

                    <!-- Wholesale Teaser Banner -->
                    @if (in_array($product->salesChannel?->slug, ['wholesale', 'both']))
                        <div class="p-4 rounded-xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-900 space-y-1.5">
                            <div class="flex items-center gap-1.5 font-bold text-indigo-950">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Wholesale Factory Rates Available for Verified Businesses
                            </div>
                            <p class="text-indigo-700 leading-relaxed">
                                Buying for a salon, boutique, or distribution business? Login as a wholesale partner to unlock bulk volume discount rates and multi-variation matrix procurement.
                            </p>
                            <div class="flex items-center gap-4 pt-1">
                                <a href="{{ route('wholesale.upgrade') }}" class="font-bold text-indigo-900 hover:text-indigo-700 underline">
                                    Apply for Wholesale Verification &rarr;
                                </a>
                                <span class="text-slate-300">|</span>
                                <a href="{{ route('demo.login', 'wholesale') }}" class="text-indigo-600 hover:underline">
                                    Demo Wholesale Login
                                </a>
                            </div>
                        </div>
                    @endif

                    <!-- Select Variation for Retail Single Purchase -->
                    @if ($product->variations->isNotEmpty())
                        <div class="space-y-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Choose Variation</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ($product->variations as $var)
                                    @php
                                        $attrs = $var->attributeValues->map(fn($av) => "{$av->attribute->name}: {$av->value}")->implode(', ');
                                    @endphp
                                    <label class="relative flex items-center p-3 rounded-xl border cursor-pointer transition {{ $selectedVariationId === $var->id ? 'border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                                        <input type="radio" wire:model.live="selectedVariationId" value="{{ $var->id }}" class="sr-only"/>
                                        <div class="text-xs">
                                            <div class="font-bold text-slate-900">{{ $attrs }}</div>
                                            <div class="text-[11px] text-slate-500 font-mono">{{ $var->sku }} • Stock: {{ $var->stock_quantity }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Quantity Counter & Add to Cart -->
                    <div class="flex items-center gap-4 pt-2">
                        <div class="w-32">
                            <label class="block text-xs font-bold text-slate-700 mb-1">Quantity</label>
                            <input 
                                type="number" 
                                min="1" 
                                wire:model="retailQuantity" 
                                class="w-full text-center py-2 px-3 border border-slate-300 rounded-xl text-sm font-bold focus:ring-2 focus:ring-indigo-500"
                            />
                        </div>

                        <div class="flex-1 pt-5">
                            <button 
                                type="button" 
                                wire:click="addToCartRetail"
                                class="w-full py-3 px-6 rounded-xl font-bold text-sm bg-indigo-600 hover:bg-indigo-700 text-white transition shadow-sm flex items-center justify-center gap-2"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                                Add to Cart (Retail Single Unit)
                            </button>
                        </div>
                    </div>
                    <div class="pt-3 text-center border-t border-slate-100">
                        <button 
                            type="button" 
                            wire:click="openRfqModal"
                            class="text-xs font-bold text-slate-600 hover:text-indigo-600 underline flex items-center justify-center gap-1 mx-auto cursor-pointer"
                        >
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Need customized manufacturing or bulk quantities? Request Factory Quote (RFQ)
                        </button>
                    </div>
                </div>
            @endif

            <!-- Full Description -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 space-y-3">
                <h3 class="font-bold text-slate-900 text-sm">Product Specifications & Description</h3>
                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">
                    {{ $product->full_description }}
                </p>
            </div>
        </div>
    </div>

    <!-- RFQ Submission Modal Dialog -->
    @if ($showRfqModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-200 relative animate-in fade-in duration-200">
                <button 
                    wire:click="closeRfqModal" 
                    class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                @if ($rfqSuccess)
                    <div class="text-center py-6 space-y-4">
                        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h3 class="text-xl font-black text-slate-900">RFQ Inquiry Submitted!</h3>
                        <p class="text-xs text-slate-600 max-w-sm mx-auto leading-relaxed">
                            Your custom quotation request for <strong class="text-slate-900">{{ $product->title }}</strong> has been dispatched to <strong>{{ $product->seller?->sellerProfile?->store_name }}</strong>. The manufacturer will review your specifications and contact you directly.
                        </p>
                        <div class="pt-2">
                            <button 
                                wire:click="closeRfqModal"
                                class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition"
                            >
                                Close & Continue Sourcing
                            </button>
                        </div>
                    </div>
                @else
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">
                            B2B RFQ Inquiry
                        </span>
                        <h2 class="text-xl font-black text-slate-900 mt-1">Request Factory Quotation</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Supplier: <strong class="text-indigo-600">{{ $product->seller?->sellerProfile?->store_name }}</strong>
                        </p>
                    </div>

                    <form wire:submit="submitRfq" class="space-y-4 text-xs">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Target Quantity (Units) *</label>
                                <input 
                                    type="number" 
                                    min="1" 
                                    wire:model="rfqTargetQuantity" 
                                    placeholder="e.g. 500" 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl font-bold focus:ring-2 focus:ring-indigo-500"
                                />
                                @error('rfqTargetQuantity') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Target Price / Unit (৳ Tk)</label>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    wire:model="rfqTargetPrice" 
                                    placeholder="Your budget in ৳" 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl font-bold focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Your Name *</label>
                                <input 
                                    type="text" 
                                    wire:model="rfqBuyerName" 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                />
                                @error('rfqBuyerName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Phone Number *</label>
                                <input 
                                    type="text" 
                                    wire:model="rfqBuyerPhone" 
                                    placeholder="+88017..." 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                />
                                @error('rfqBuyerPhone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Company / Store Name</label>
                                <input 
                                    type="text" 
                                    wire:model="rfqCompanyName" 
                                    placeholder="e.g. Boutique or Salon Name" 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Destination District *</label>
                                <input 
                                    type="text" 
                                    wire:model="rfqDestinationDistrict" 
                                    placeholder="e.g. Dhaka, Chittagong, Sylhet" 
                                    class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Custom Specifications / Packaging / OEM Notes</label>
                            <textarea 
                                rows="3" 
                                wire:model="rfqSpecifications" 
                                placeholder="Describe required color distribution, custom logo stamping, delivery timeline, or bulk carton specifications..." 
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            ></textarea>
                        </div>

                        <div class="pt-2 flex items-center justify-end gap-3">
                            <button 
                                type="button" 
                                wire:click="closeRfqModal" 
                                class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold"
                            >
                                Cancel
                            </button>
                            <button 
                                type="submit" 
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold transition shadow-sm flex items-center gap-1.5"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                Send Inquiry to Supplier
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
