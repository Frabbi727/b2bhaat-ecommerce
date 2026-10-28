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

                <div class="flex items-start justify-between gap-4">
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                        {{ $product->title }}
                    </h1>
                    <button
                        wire:click="toggleWishlist"
                        type="button"
                        class="shrink-0 p-2.5 rounded-xl border transition-all flex items-center gap-1.5 text-xs font-semibold {{ $isInWishlist ? 'bg-rose-50 border-rose-300 text-rose-600 shadow-xs' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 hover:text-slate-900' }}"
                        title="{{ $isInWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' }}"
                    >
                        <svg class="w-5 h-5 {{ $isInWishlist ? 'fill-rose-500 text-rose-500' : 'text-slate-400' }}" fill="{{ $isInWishlist ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                        </svg>
                        <span class="hidden sm:inline">{{ $isInWishlist ? 'Wishlisted' : 'Save' }}</span>
                    </button>
                </div>

                <div class="flex items-center gap-2 mt-2">
                    <div class="flex items-center text-amber-400 text-sm">
                        @for ($i = 1; $i <= 5; $i++)
                            <span>{{ $i <= round($averageRating) ? '★' : '☆' }}</span>
                        @endfor
                    </div>
                    <span class="text-xs font-bold text-slate-700">{{ $averageRating > 0 ? number_format($averageRating, 1) : 'New' }}</span>
                    <span class="text-xs text-slate-400">•</span>
                    <a href="#reviews-section" class="text-xs font-semibold text-indigo-600 hover:underline">
                        {{ $totalReviews }} {{ Str::plural('review', $totalReviews) }}
                    </a>
                </div>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                    {{ $product->short_description }}
                </p>
            </div>

            <!-- Merchant Vacation Mode Notice Banner -->
            @if ($this->isSellerOnVacation)
                <div class="p-5 rounded-2xl bg-amber-50 border border-amber-300 text-amber-900 shadow-sm flex items-start gap-3.5">
                    <span class="text-3xl p-2 bg-amber-100/80 rounded-xl">🏖️</span>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <h4 class="font-bold text-sm text-amber-950">
                                {{ $product->seller?->sellerProfile?->store_name }} is Currently on Vacation Mode
                            </h4>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-200 text-amber-800 uppercase tracking-wider">
                                Orders Paused
                            </span>
                        </div>
                        <p class="text-xs text-amber-800/90 leading-relaxed">
                            {{ $product->seller?->sellerProfile?->vacation_message ?: 'This factory is temporarily closed for operations and dispatches.' }}
                        </p>
                        @if ($product->seller?->sellerProfile?->vacation_ends_at)
                            <div class="text-xs font-semibold text-amber-900 pt-0.5 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Expected factory reopening: {{ \Carbon\Carbon::parse($product->seller->sellerProfile->vacation_ends_at)->format('M d, Y') }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif

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

                    <!-- Alpine.js Multi-Variation Bulk Matrix with Dynamic Volume Tier Calculation -->
                    <div 
                        x-data="{
                            tiers: {{ json_encode($wholesaleTiers->map(fn($t) => ['min' => $t->min_quantity, 'max' => $t->max_quantity, 'price' => (float)$t->unit_price])) }},
                            moq: {{ $wholesalePrice?->min_quantity ?? 1 }},
                            quantities: @entangle('matrixQuantities'),
                            get totalUnits() {
                                return Object.values(this.quantities).reduce((acc, val) => acc + (parseInt(val) || 0), 0);
                            },
                            get currentTier() {
                                if (!this.tiers || this.tiers.length === 0) return null;
                                let matched = this.tiers[0];
                                for (let t of this.tiers) {
                                    if (this.totalUnits >= t.min) {
                                        matched = t;
                                    }
                                }
                                return matched;
                            },
                            get unitPrice() {
                                return this.currentTier ? this.currentTier.price : {{ $wholesalePrice?->unit_price ?? 0 }};
                            },
                            get grandTotal() {
                                return (this.totalUnits * this.unitPrice).toFixed(2);
                            },
                            get isMoqMet() {
                                return this.totalUnits >= this.moq;
                            }
                        }"
                        class="space-y-5"
                    >
                        <!-- Progressive Wholesale Volume Discount Tier Cards -->
                        @if ($wholesaleTiers->isNotEmpty())
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-700 uppercase tracking-wider text-[11px]">Factory Volume Tier Brackets</span>
                                    <span class="text-emerald-700 font-semibold text-[11px]" x-show="isMoqMet" x-cloak>
                                        Active Unit Rate: <strong class="text-sm">৳<span x-text="unitPrice.toFixed(2)"></span></strong>
                                    </span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    @foreach ($wholesaleTiers as $tier)
                                        @php
                                            $rangeLabel = $tier->max_quantity 
                                                ? "{$tier->min_quantity} – {$tier->max_quantity} {$product->unit?->symbol}" 
                                                : "{$tier->min_quantity}+ {$product->unit?->symbol}";
                                            
                                            $savingsPercent = $retailPrice && $retailPrice->unit_price > 0
                                                ? round((($retailPrice->unit_price - $tier->unit_price) / $retailPrice->unit_price) * 100)
                                                : 0;
                                        @endphp
                                        <div 
                                            class="p-3.5 rounded-xl border text-center transition space-y-1 relative"
                                            :class="totalUnits >= {{ $tier->min_quantity }} && (!{{ $tier->max_quantity ?? 'null' }} || totalUnits <= {{ $tier->max_quantity ?? 999999 }}) ? 'border-emerald-500 bg-emerald-50/70 ring-2 ring-emerald-500/20' : 'border-slate-200 bg-slate-50/60'"
                                        >
                                            @if ($savingsPercent > 0)
                                                <span class="absolute -top-2 right-2 px-1.5 py-0.5 rounded text-[9px] font-black bg-rose-500 text-white uppercase tracking-wider">
                                                    Save {{ $savingsPercent }}%
                                                </span>
                                            @endif
                                            <div class="text-[11px] font-bold text-slate-500">{{ $rangeLabel }}</div>
                                            <div class="text-xl font-black text-slate-900">৳{{ number_format($tier->unit_price, 2) }}</div>
                                            <div class="text-[10px] text-slate-400">per {{ $product->unit?->symbol }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center justify-between text-xs pt-1">
                            <h3 class="font-bold text-slate-900">B2B Bulk Variation Matrix</h3>
                            <span class="text-slate-500">Enter order quantity across variations</span>
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
                                                @if ($var->stock_quantity <= 0)
                                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-rose-50 text-rose-700">
                                                        0 {{ $product->unit?->symbol }} (Out)
                                                    </span>
                                                @elseif ($var->stock_quantity <= 10)
                                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700">
                                                        ⚠️ {{ $var->stock_quantity }} {{ $product->unit?->symbol }} (Low)
                                                    </span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-50 text-emerald-700">
                                                        {{ $var->stock_quantity }} {{ $product->unit?->symbol }}
                                                    </span>
                                                @endif
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

                        @if ($this->isSellerOnVacation)
                            <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-center text-xs text-amber-800 font-bold flex items-center justify-center gap-2">
                                <span>🏖️</span>
                                <span>Factory dispatches paused in Vacation Mode. Bulk ordering temporarily disabled.</span>
                            </div>
                        @else
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
                        @endif

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

                    <!-- Wholesale Teaser Banner with Volume Discount Preview -->
                    @if (in_array($product->salesChannel?->slug, ['wholesale', 'both']))
                        <div class="p-4 rounded-xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-900 space-y-2">
                            <div class="flex items-center gap-1.5 font-bold text-indigo-950">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Wholesale Factory Volume Rates Available
                            </div>
                            <p class="text-indigo-700 leading-relaxed text-[11px]">
                                Sourcing for a business, salon, or retail boutique? Verified buyers unlock direct factory bulk tiers starting from MOQ {{ $wholesalePrice?->min_quantity ?? 1 }} {{ $product->unit?->symbol }}.
                            </p>
                            @if ($wholesaleTiers->isNotEmpty())
                                <div class="grid grid-cols-3 gap-2 pt-1 text-center font-mono text-[10px]">
                                    @foreach ($wholesaleTiers as $t)
                                        <div class="p-1.5 rounded-lg bg-white/80 border border-indigo-100">
                                            <div class="text-slate-500 font-sans">{{ $t->min_quantity }}{{ $t->max_quantity ? '–'.$t->max_quantity : '+' }} {{ $product->unit?->symbol }}</div>
                                            <div class="font-bold text-indigo-950">৳{{ number_format($t->unit_price, 2) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
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
                                            <div class="text-[11px] font-mono flex items-center gap-2">
                                                <span class="text-slate-500">{{ $var->sku }}</span>
                                                <span class="text-slate-300">•</span>
                                                @if ($var->stock_quantity <= 0)
                                                    <span class="text-rose-600 font-bold">Out of Stock</span>
                                                @elseif ($var->stock_quantity <= 10)
                                                    <span class="text-amber-600 font-bold">⚠️ Only {{ $var->stock_quantity }} left (Low)</span>
                                                @else
                                                    <span class="text-slate-500">Stock: {{ $var->stock_quantity }}</span>
                                                @endif
                                            </div>
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

                        @if ($this->isSellerOnVacation)
                            <div class="flex-1 pt-5">
                                <button 
                                    type="button" 
                                    disabled
                                    class="w-full py-3 px-6 rounded-xl font-bold text-sm bg-slate-200 text-slate-500 cursor-not-allowed flex items-center justify-center gap-2"
                                >
                                    <span>🏖️</span>
                                    <span>Store on Vacation (Ordering Disabled)</span>
                                </button>
                            </div>
                        @else
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
                        @endif
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

    <!-- Customer & Wholesale Buyer Reviews Section -->
    <div id="reviews-section" class="mt-12 bg-white rounded-3xl border border-slate-200 p-6 sm:p-10 shadow-xs space-y-8">
        <div class="border-b border-slate-100 pb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-amber-100 text-amber-800">
                        Ratings & Experiences
                    </span>
                    <span class="text-xs text-slate-400">•</span>
                    <span class="text-xs text-slate-500 font-medium">Verified B2B & Retail Feedback</span>
                </div>
                <h2 class="text-2xl font-black text-slate-900 mt-1">Buyer Reviews & Ratings</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Authentic feedback from verified wholesale retailers, salon operators, and individual buyers.
                </p>
            </div>

            @auth
                @if ($userHasPurchased)
                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Verified Buyer of this Product
                    </div>
                @endif
            @endauth
        </div>

        <!-- Rating Summary & Write Review Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Left: Breakdown Overview (5 cols) -->
            <div class="lg:col-span-5 bg-slate-50 rounded-2xl p-6 border border-slate-100 space-y-6">
                <div class="flex items-center gap-5">
                    <div class="text-center">
                        <div class="text-5xl font-black text-slate-900 leading-none">
                            {{ $averageRating > 0 ? number_format($averageRating, 1) : '0.0' }}
                        </div>
                        <div class="text-xs text-slate-500 font-medium mt-1">out of 5.0</div>
                    </div>

                    <div class="space-y-1">
                        <div class="flex items-center text-amber-400 text-lg">
                            @for ($i = 1; $i <= 5; $i++)
                                <span>{{ $i <= round($averageRating) ? '★' : '☆' }}</span>
                            @endfor
                        </div>
                        <div class="text-xs font-semibold text-slate-700">
                            {{ $totalReviews }} total customer {{ Str::plural('rating', $totalReviews) }}
                        </div>
                    </div>
                </div>

                <!-- Star Rating Bars -->
                <div class="space-y-2 pt-2 border-t border-slate-200/60 text-xs">
                    @foreach ([5, 4, 3, 2, 1] as $star)
                        <div class="flex items-center gap-3">
                            <button 
                                type="button" 
                                wire:click="setFilterRating('{{ $star }}')"
                                class="w-14 text-left font-semibold text-slate-600 hover:text-indigo-600 transition flex items-center gap-1 shrink-0 cursor-pointer"
                            >
                                <span>{{ $star }}</span>
                                <span class="text-amber-400">★</span>
                            </button>
                            <div class="flex-1 h-2 rounded-full bg-slate-200 overflow-hidden">
                                <div 
                                    class="h-full bg-amber-400 rounded-full transition-all duration-300" 
                                    style="width: {{ $ratingDistribution[$star]['percentage'] }}%"
                                ></div>
                            </div>
                            <span class="w-10 text-right text-slate-400 font-mono text-[11px] shrink-0">
                                {{ $ratingDistribution[$star]['count'] }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <!-- Filter Pills -->
                <div class="pt-4 border-t border-slate-200/60 flex flex-wrap gap-1.5 text-xs">
                    <button 
                        type="button" 
                        wire:click="setFilterRating('all')" 
                        class="px-2.5 py-1 rounded-lg font-semibold transition cursor-pointer {{ $reviewFilterRating === 'all' ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' }}"
                    >
                        All ({{ $totalReviews }})
                    </button>
                    <button 
                        type="button" 
                        wire:click="setFilterRating('verified')" 
                        class="px-2.5 py-1 rounded-lg font-semibold transition cursor-pointer {{ $reviewFilterRating === 'verified' ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' }}"
                    >
                        Verified Only
                    </button>
                    @foreach ([5, 4, 3] as $star)
                        <button 
                            type="button" 
                            wire:click="setFilterRating('{{ $star }}')" 
                            class="px-2 py-1 rounded-lg font-semibold transition cursor-pointer {{ $reviewFilterRating === (string) $star ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ $star }}★
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Right: Submit Review Form (7 cols) -->
            <div class="lg:col-span-7 bg-white rounded-2xl p-6 border border-slate-200 space-y-4">
                <h3 class="text-sm font-bold text-slate-900 flex items-center justify-between">
                    <span>{{ $existingUserReview ? 'Update Your Review' : 'Write a Product Review' }}</span>
                    @auth
                        @if ($existingUserReview)
                            <span class="text-[11px] font-normal text-indigo-600">You previously rated this {{ $existingUserReview->rating }}★</span>
                        @endif
                    @endauth
                </h3>

                @if ($reviewSuccessMessage)
                    <div class="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ $reviewSuccessMessage }}</span>
                    </div>
                @endif

                @if ($reviewErrorMessage)
                    <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>{{ $reviewErrorMessage }}</span>
                    </div>
                @endif

                @auth
                    <form wire:submit="submitReview" class="space-y-4 text-xs">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1.5">Overall Rating *</label>
                            <div class="flex items-center gap-2" x-data="{ hovered: 0 }">
                                @for ($i = 1; $i <= 5; $i++)
                                    <button 
                                        type="button" 
                                        wire:click="$set('reviewRating', {{ $i }})"
                                        @mouseenter="hovered = {{ $i }}"
                                        @mouseleave="hovered = 0"
                                        class="text-2xl transition transform hover:scale-110 cursor-pointer focus:outline-hidden"
                                    >
                                        <span x-show="hovered === 0" class="{{ $i <= $reviewRating ? 'text-amber-400' : 'text-slate-300' }}">★</span>
                                        <span x-show="hovered > 0" x-cloak :class="hovered >= {{ $i }} ? 'text-amber-400' : 'text-slate-300'">★</span>
                                    </button>
                                @endfor
                                <span class="ml-2 font-bold text-slate-700">
                                    {{ $reviewRating }} of 5 Stars
                                </span>
                            </div>
                            @error('reviewRating') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Headline / Review Summary (Optional)</label>
                            <input 
                                type="text" 
                                wire:model="reviewTitle" 
                                placeholder="e.g. Great quality for my salon clients or Fast dispatch" 
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            />
                            @error('reviewTitle') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Detailed Review *</label>
                            <textarea 
                                rows="3" 
                                wire:model="reviewComment" 
                                placeholder="Share your experience regarding texture, color consistency, packaging, or customer satisfaction..." 
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            ></textarea>
                            @error('reviewComment') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <span class="text-[11px] text-slate-400">
                                @if ($userHasPurchased)
                                    <span class="text-emerald-700 font-semibold">✓ Verified Purchase</span> badge will be attached.
                                @else
                                    Public marketplace buyer review.
                                @endif
                            </span>

                            <button 
                                type="submit" 
                                wire:loading.attr="disabled"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold transition shadow-xs flex items-center gap-1.5 cursor-pointer"
                            >
                                <svg wire:loading class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                <span>{{ $existingUserReview ? 'Update Review' : 'Submit Review' }}</span>
                            </button>
                        </div>
                    </form>
                @else
                    <div class="py-6 px-4 bg-slate-50 rounded-xl border border-dashed border-slate-300 text-center space-y-3">
                        <div class="w-10 h-10 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center mx-auto">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </div>
                        <div>
                            <p class="font-bold text-slate-800">Purchased or tried this product?</p>
                            <p class="text-xs text-slate-500 mt-0.5">Please sign in to write an authentic review and share your rating with other buyers.</p>
                        </div>
                        <div class="flex items-center justify-center gap-2 pt-1 text-xs">
                            <a href="{{ route('demo.login', 'retail') }}" class="px-3.5 py-1.5 bg-white border border-slate-300 rounded-lg font-bold text-slate-700 hover:bg-slate-100">
                                Demo Retail Login
                            </a>
                            <a href="{{ route('demo.login', 'wholesale') }}" class="px-3.5 py-1.5 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700">
                                Demo Wholesale Login
                            </a>
                        </div>
                    </div>
                @endauth
            </div>
        </div>

        <!-- Reviews List -->
        <div class="space-y-4 pt-6 border-t border-slate-100">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900">
                    Showing {{ $reviews->count() }} {{ $reviewFilterRating === 'all' ? 'Reviews' : ($reviewFilterRating === 'verified' ? 'Verified Reviews' : $reviewFilterRating . '★ Reviews') }}
                </h3>
            </div>

            @if ($reviews->isEmpty())
                <div class="py-12 text-center bg-slate-50 rounded-2xl border border-slate-100 space-y-2">
                    <div class="text-3xl text-slate-300">★</div>
                    <p class="text-sm font-bold text-slate-700">No reviews found for this filter</p>
                    <p class="text-xs text-slate-400">Be the first to share your experience with this factory product.</p>
                </div>
            @else
                <div class="grid grid-cols-1 gap-4">
                    @foreach ($reviews as $rev)
                        <div class="p-5 rounded-2xl border border-slate-200 bg-white hover:border-slate-300 transition space-y-3">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 font-black text-xs flex items-center justify-center">
                                            {{ strtoupper(substr($rev->user?->name ?? 'Buyer', 0, 1)) }}
                                        </div>
                                        <span class="font-bold text-xs text-slate-900">{{ $rev->user?->name ?? 'Customer' }}</span>

                                        @if ($rev->is_verified_purchase)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                Verified Purchase
                                            </span>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <div class="flex text-amber-400 text-xs">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <span>{{ $i <= $rev->rating ? '★' : '☆' }}</span>
                                            @endfor
                                        </div>
                                        <span class="text-[11px] text-slate-400 font-medium">
                                            {{ $rev->created_at->diffForHumans() }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            @if ($rev->title)
                                <h4 class="text-xs font-bold text-slate-900">{{ $rev->title }}</h4>
                            @endif

                            <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">{{ $rev->comment }}</p>

                            <!-- Official Seller Reply -->
                            @if ($rev->seller_reply)
                                <div class="mt-3 p-3.5 rounded-xl bg-indigo-50/70 border border-indigo-100 text-xs space-y-1.5 ml-4">
                                    <div class="flex items-center justify-between text-indigo-950 font-bold text-[11px]">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-base">🏭</span>
                                            <span>Factory Response from {{ $product->seller?->sellerProfile?->store_name ?? 'Manufacturer' }}</span>
                                        </div>
                                        <span class="text-[10px] text-indigo-500 font-normal">
                                            {{ $rev->seller_replied_at?->diffForHumans() }}
                                        </span>
                                    </div>
                                    <p class="text-indigo-900 text-xs leading-relaxed">
                                        {{ $rev->seller_reply }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
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
