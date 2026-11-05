@push('jsonld')
<script type="application/ld+json">
{
  "@@context": "https://schema.org/",
  "@@type": "Product",
  "name": {{ json_encode($product->title) }},
  "image": [{{ json_encode($product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=1000') }}],
  "description": {{ json_encode($product->short_description ?? $product->title) }},
  "sku": {{ json_encode($product->sku ?? $product->slug) }},
  "mpn": {{ json_encode($product->model_number ?? $product->sku ?? $product->slug) }},
  "brand": {
    "@@type": "Brand",
    "name": {{ json_encode($product->brand?->name ?? 'Haier') }}
  },
  "offers": {
    "@@type": "Offer",
    "url": {{ json_encode(url()->current()) }},
    "priceCurrency": "BDT",
    "price": "{{ number_format($product->effective_selling_price, 2, '.', '') }}",
    "priceValidUntil": "{{ now()->addYear()->format('Y-m-d') }}",
    "itemCondition": "https://schema.org/NewCondition",
    "availability": "https://schema.org/InStock"
  }
}
</script>
@endpush

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-12">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-slate-500 overflow-x-auto no-scrollbar">
        <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600 transition shrink-0">Home</a>
        <span>/</span>
        <a href="{{ route('catalog.index', ['category' => $product->category?->id]) }}" class="hover:text-indigo-600 transition shrink-0">
            {{ $product->category?->name ?? 'Catalog' }}
        </a>
        @if ($product->brand)
            <span>/</span>
            <span class="hover:text-indigo-600 transition shrink-0">{{ $product->brand->name }}</span>
        @endif
        <span>/</span>
        <span class="text-slate-900 font-bold truncate">{{ $product->title }}</span>
    </nav>

    <!-- Feedback Alerts -->
    @if ($feedbackMessage)
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <a href="{{ route('cart.index') }}" class="font-bold underline text-emerald-900">View Cart & Checkout &rarr;</a>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2 shadow-xs">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    @if ($this->isSellerOnVacation)
        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-3 shadow-xs">
            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div class="space-y-1">
                <div class="font-bold text-amber-950">Currently on Vacation Mode</div>
                <p class="text-xs text-amber-800 leading-relaxed">
                    {{ $product->seller?->sellerProfile?->vacation_message ?: 'Factory closed for Eid holidays. Orders temporarily paused.' }}
                </p>
                @if ($product->seller?->sellerProfile?->vacation_ends_at)
                    <div class="text-[11px] text-amber-900 font-semibold">
                        Expected factory reopening: {{ \Carbon\Carbon::parse($product->seller->sellerProfile->vacation_ends_at)->format('M d, Y') }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- ================= MAIN PRODUCT HERO SECTION ================= -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">
        <!-- LEFT: MULTI-IMAGE GALLERY (Pickaboo Reference Standard) -->
        <div class="lg:col-span-6 space-y-4">
            @php
                $galleryImages = $product->images->isNotEmpty()
                    ? $product->images->pluck('public_url')->toArray()
                    : [$product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=1000'];
                $currentMainImage = $galleryImages[$activeImageIndex] ?? $galleryImages[0];
            @endphp

            <!-- Main Stage Image with Zoom & Fullscreen -->
            <div
                x-data="{ zoomed: false, originX: '50%', originY: '50%' }"
                @mousemove="
                    const rect = $el.getBoundingClientRect();
                    const x = (($event.clientX - rect.left) / rect.width) * 100;
                    const y = (($event.clientY - rect.top) / rect.height) * 100;
                    originX = x + '%';
                    originY = y + '%';
                "
                class="relative rounded-3xl bg-white border border-slate-200 overflow-hidden shadow-sm aspect-4/3 sm:aspect-square flex items-center justify-center cursor-zoom-in"
                @mouseenter="zoomed = true"
                @mouseleave="zoomed = false"
            >
                <img
                    src="{{ $currentMainImage }}"
                    alt="{{ $product->title }}"
                    class="w-full h-full object-contain p-4 transition-transform duration-200 ease-out"
                    :style="zoomed ? `transform: scale(1.6); transform-origin: ${originX} ${originY};` : 'transform: scale(1);'"
                />

                <!-- Badges Overlay -->
                <div class="absolute top-4 left-4 flex flex-col gap-1.5 pointer-events-none">
                    @if ($product->is_flash_sale_active)
                        <span class="px-2.5 py-1 rounded-lg bg-rose-600 text-white font-black text-xs shadow-md">
                            ⚡ Flash Sale -{{ $product->discount_percentage }}%
                        </span>
                    @elseif ($product->discount_percentage)
                        <span class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-extrabold text-xs shadow-md">
                            -{{ $product->discount_percentage }}% OFF
                        </span>
                    @endif
                    @if ($product->warranty_period)
                        <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 text-[10px] font-bold">
                            🛡️ Official Warranty
                        </span>
                    @endif
                </div>

                <!-- Fullscreen Lightbox Button -->
                <button
                    type="button"
                    wire:click="toggleFullscreenGallery"
                    class="absolute bottom-4 right-4 p-2.5 rounded-xl bg-white/90 hover:bg-white text-slate-700 shadow-md backdrop-blur-sm transition z-10"
                    title="View Fullscreen"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                </button>
            </div>

            <!-- Thumbnail Navigation Strip -->
            @if (count($galleryImages) > 1)
                <div class="flex items-center gap-3 overflow-x-auto py-2 no-scrollbar">
                    @foreach ($galleryImages as $idx => $img)
                        <button
                            type="button"
                            wire:click="selectImage({{ $idx }})"
                            class="w-18 h-18 sm:w-20 sm:h-20 rounded-2xl bg-white p-1 border-2 transition shrink-0 aspect-square overflow-hidden cursor-pointer {{ $activeImageIndex === $idx ? 'border-indigo-600 shadow-md ring-2 ring-indigo-200' : 'border-slate-200 hover:border-slate-400' }}"
                            aria-label="View thumbnail {{ $idx + 1 }}"
                        >
                            <img src="{{ $img }}" alt="Thumbnail {{ $idx + 1 }}" class="w-full h-full object-contain rounded-xl"/>
                        </button>
                    @endforeach
                </div>
            @endif

            <!-- Verified Merchant Guarantee Card -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 font-medium">Sold & Fulfilled by:</span>
                    @if ($product->seller?->sellerProfile?->store_slug)
                        <a href="{{ route('store.show', $product->seller->sellerProfile->store_slug) }}" class="font-bold text-indigo-600 hover:text-indigo-800 hover:underline">
                            {{ $product->seller->sellerProfile->store_name }} &rarr;
                        </a>
                    @else
                        <span class="font-bold text-slate-800">{{ $product->seller?->name ?? 'Verified Merchant' }}</span>
                    @endif
                </div>
                <div class="flex items-center justify-between text-slate-600">
                    <span>Merchant Status:</span>
                    <span class="font-bold text-emerald-700 flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        100% Verified Bangladesh Business
                    </span>
                </div>
                @if ($product->unit)
                    <div class="flex items-center justify-between text-slate-600">
                        <span>Commercial Unit:</span>
                        <span class="font-medium text-slate-900">{{ $product->unit->name }} ({{ $product->unit->symbol }})</span>
                    </div>
                @endif
            </div>
        </div>

        <!-- RIGHT: PRODUCT PURCHASING INFORMATION & DETAILS -->
        <div class="lg:col-span-6 space-y-6">
            <!-- Brand, SKU, Model -->
            <div class="space-y-2">
                <div class="flex items-center gap-2 flex-wrap text-xs">
                    @if ($product->brand)
                        <span class="px-2.5 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 font-bold uppercase tracking-wider">
                            {{ $product->brand->name }}
                        </span>
                    @endif
                    @if ($product->model_number)
                        <span class="text-slate-500 font-mono text-[11px]">Model: <strong class="text-slate-800">{{ $product->model_number }}</strong></span>
                    @endif
                    @if ($product->sku)
                        <span class="text-slate-300">•</span>
                        <span class="text-slate-500 font-mono text-[11px]">SKU: {{ $product->sku }}</span>
                    @endif
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 leading-tight">
                    {{ $product->title }}
                </h1>

                <!-- Rating & Reviews Anchor -->
                <div class="flex items-center gap-3 text-xs">
                    <div class="flex items-center text-amber-400">
                        @for ($i = 1; $i <= 5; $i++)
                            <span>{{ $i <= round($averageRating) ? '★' : '☆' }}</span>
                        @endfor
                    </div>
                    <span class="font-bold text-slate-800">{{ $averageRating > 0 ? number_format($averageRating, 1) : 'New' }}</span>
                    <span class="text-slate-300">•</span>
                    <button type="button" @click="$wire.set('activeTab', 'reviews')" class="text-indigo-600 font-semibold hover:underline cursor-pointer">
                        {{ $totalReviews }} {{ Str::plural('rating', $totalReviews) }}
                    </button>
                    <span class="text-slate-300">•</span>
                    <span class="text-emerald-700 font-semibold">In Stock & Ready to Dispatch</span>
                </div>
            </div>

            <!-- Pricing & Savings Block (Pickaboo Standard) -->
            <div class="p-5 rounded-3xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div class="flex items-baseline gap-3 flex-wrap">
                    <span class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight">
                        ৳{{ number_format($product->effective_selling_price, 0) }}
                    </span>
                    @if ($product->regular_price && (float) $product->regular_price > $product->effective_selling_price)
                        <span class="text-base sm:text-lg text-slate-400 line-through">
                            ৳{{ number_format((float) $product->regular_price, 0) }}
                        </span>
                        <span class="px-2 py-0.5 rounded-lg bg-rose-600 text-white font-extrabold text-xs">
                            -{{ $product->discount_percentage }}%
                        </span>
                        <span class="text-xs font-bold text-emerald-700 bg-emerald-100/60 px-2 py-0.5 rounded-lg">
                            Save ৳{{ number_format($product->savings_amount, 0) }}
                        </span>
                    @endif
                </div>

                <!-- EMI & Bank Payment Offer Notice -->
                <div class="pt-3 border-t border-slate-200/60 flex items-center gap-2 text-xs text-slate-700">
                    <span class="w-6 h-6 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold shrink-0 text-[10px]">
                        EMI
                    </span>
                    <div>
                        @php
                            $monthlyEmi = round($product->effective_selling_price / 12);
                        @endphp
                        <span>Available with <strong>0% Interest EMI</strong> from <strong>৳{{ number_format($monthlyEmi, 0) }}/mo</strong> on 18 leading bank cards.</span>
                    </div>
                </div>

                <!-- Islandwide Delivery & Warranty Guarantees -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2 text-[11px] text-slate-600">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>{{ $product->delivery_info ?? 'Express 24h delivery inside Dhaka' }}</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <span>{{ $product->warranty_period ?? 'Official Manufacturer Warranty' }}</span>
                    </div>
                </div>
            </div>

            <!-- Product Variations Selection -->
            @if ($product->variations->isNotEmpty())
                <div class="space-y-3">
                    <div class="text-xs font-bold text-slate-900 uppercase tracking-wider">
                        Available Variations & Specifications
                    </div>
                    <div class="flex flex-wrap gap-2.5">
                        @foreach ($product->variations as $var)
                            @php
                                $varAttrLabel = $var->attributeValues->map(fn($av) => $av->value)->implode(' - ');
                            @endphp
                            <button
                                type="button"
                                wire:click="$set('selectedVariationId', {{ $var->id }})"
                                class="px-3.5 py-2 rounded-xl text-xs font-bold border transition text-left cursor-pointer {{ $selectedVariationId === $var->id ? 'bg-indigo-600 text-white border-indigo-600 shadow-md ring-2 ring-indigo-200' : 'bg-white text-slate-700 border-slate-300 hover:border-slate-400' }}"
                            >
                                <div>{{ $varAttrLabel ?: $var->sku }}</div>
                                <div class="text-[10px] {{ $selectedVariationId === $var->id ? 'text-indigo-100' : 'text-slate-400' }} font-normal">
                                    Stock: {{ $var->stock_quantity }} units
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Retail Quantity & Purchase Actions -->
            <div class="space-y-4 pt-2">
                @if ($this->isSellerOnVacation)
                    <div class="p-3 rounded-2xl bg-amber-100/80 border border-amber-300 text-amber-900 font-bold text-xs text-center">
                        Store on Vacation (Ordering Disabled)
                    </div>
                @else
                    <div class="flex items-center gap-4">
                        <!-- Quantity Stepper -->
                        <div class="flex items-center border border-slate-300 rounded-2xl bg-white shadow-xs overflow-hidden">
                            <button
                                type="button"
                                wire:click="$set('retailQuantity', {{ max(1, $retailQuantity - 1) }})"
                                class="w-10 h-11 flex items-center justify-center text-slate-600 hover:bg-slate-100 font-black text-base transition"
                            >
                                -
                            </button>
                            <input
                                type="number"
                                min="1"
                                wire:model.live="retailQuantity"
                                class="w-14 h-11 text-center font-bold text-sm text-slate-900 border-0 focus:ring-0 focus:outline-none"
                            />
                            <button
                                type="button"
                                wire:click="$set('retailQuantity', {{ $retailQuantity + 1 }})"
                                class="w-10 h-11 flex items-center justify-center text-slate-600 hover:bg-slate-100 font-black text-base transition"
                            >
                                +
                            </button>
                        </div>

                        <!-- Add to Cart -->
                        <button
                            type="button"
                            wire:click="addToCartRetail"
                            class="flex-1 h-11 px-5 rounded-2xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md transition flex items-center justify-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                            <span>Add to Cart</span>
                        </button>

                        <!-- Buy Now (Instant Checkout) -->
                        <button
                            type="button"
                            wire:click="buyNowRetail"
                            class="flex-1 h-11 px-5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md transition flex items-center justify-center gap-1.5"
                        >
                            <span>Buy Now</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>

                        <!-- Wishlist Toggle Button -->
                        <button
                            type="button"
                            wire:click="toggleWishlist"
                            class="h-11 w-11 rounded-2xl border transition flex items-center justify-center shrink-0 {{ $isInWishlist ? 'bg-rose-50 border-rose-300 text-rose-600' : 'bg-white border-slate-300 text-slate-600 hover:border-slate-400' }}"
                            title="{{ $isInWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' }}"
                        >
                            <svg class="w-5 h-5 {{ $isInWishlist ? 'fill-rose-500 text-rose-500' : 'currentColor' }}" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                        </button>
                    </div>
                @endif
            </div>

            @if ($canSeeWholesalePrices)
            <!-- B2B Wholesale Bulk Matrix Accordion / Action -->
            <div class="pt-4 border-t border-slate-200 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span>Factory Volume Tier Brackets</span>
                        <span class="px-1.5 py-0.2 rounded bg-indigo-100 text-indigo-700 text-[10px] font-bold">B2B</span>
                    </span>
                    <button
                        type="button"
                        wire:click="openRfqModal"
                        class="text-xs font-bold text-indigo-600 hover:text-indigo-800 hover:underline"
                    >
                        Request Custom RFQ &rarr;
                    </button>
                </div>

                @if ($wholesaleTiers->isNotEmpty())
                    <div class="space-y-2">
                        <div class="text-[11px] font-semibold text-slate-600">
                            Wholesale Factory Volume Rates Available:
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                            @foreach ($wholesaleTiers as $tier)
                                @php
                                    $rangeLabel = $tier->max_quantity 
                                        ? "{$tier->min_quantity} – {$tier->max_quantity} {$product->unit?->symbol}" 
                                        : "{$tier->min_quantity}+ {$product->unit?->symbol}";
                                @endphp
                                <div class="p-3 rounded-xl bg-indigo-50/60 border border-indigo-100 text-center space-y-0.5">
                                    <div class="text-[11px] font-bold text-slate-500">{{ $rangeLabel }}</div>
                                    <div class="text-base font-black text-indigo-900">৳{{ number_format((float) $tier->unit_price, 2) }}</div>
                                    <div class="text-[10px] text-slate-400">per {{ $product->unit?->symbol }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- If Wholesale Buyer is logged in, show Bulk Variation Matrix Table -->
                @if ($isWholesaleUser && $product->variations->isNotEmpty())
                    <div class="space-y-3 pt-2">
                        <div class="text-xs font-bold text-slate-900">B2B Bulk Variation Order Matrix</div>
                        <div class="border border-slate-200 rounded-2xl overflow-hidden">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                                    <tr>
                                        <th class="p-2.5">SKU / Attributes</th>
                                        <th class="p-2.5">Stock</th>
                                        <th class="p-2.5 text-right w-28">Order Qty</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($product->variations as $var)
                                        <tr class="hover:bg-slate-50/60">
                                            <td class="p-2.5 font-semibold text-slate-800">
                                                {{ $var->sku }}
                                                <span class="text-[10px] text-slate-400 font-normal block">{{ $var->attributeValues->pluck('value')->implode(', ') }}</span>
                                            </td>
                                            <td class="p-2.5 text-slate-500">{{ $var->stock_quantity }} units</td>
                                            <td class="p-2.5 text-right">
                                                <input
                                                    type="number"
                                                    min="0"
                                                    wire:model="matrixQuantities.{{ $var->id }}"
                                                    class="w-20 text-right py-1 px-2 border border-slate-300 rounded-lg text-xs font-bold"
                                                />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($this->isSellerOnVacation)
                            <button
                                disabled
                                class="w-full py-2.5 rounded-xl bg-slate-200 text-slate-500 font-bold text-xs cursor-not-allowed"
                            >
                                Factory dispatches paused in Vacation Mode
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="addBulkMatrixToCart"
                                class="w-full py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs tracking-wide shadow-xs transition"
                            >
                                Add Bulk Wholesale Lot to Cart
                            </button>
                        @endif
                    </div>
                @endif
            </div>
            @elseif ($wholesaleTiers->isNotEmpty())
            <div class="pt-4 border-t border-slate-200">
                <div class="p-4 rounded-xl bg-indigo-50/60 border border-indigo-100 text-xs text-slate-700 space-y-1">
                    <div class="font-bold text-indigo-900">Wholesale pricing available</div>
                    <p>Factory volume tier rates are shown to verified business buyers.
                        <a href="{{ route('wholesale.upgrade') }}" class="font-bold text-indigo-600 hover:underline">Apply for a wholesale account &rarr;</a>
                    </p>
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- ================= FREQUENTLY BOUGHT TOGETHER (Pickaboo Reference) ================= -->
    @if ($frequentlyBoughtTogether->isNotEmpty())
        @php
            $bundleItem = $frequentlyBoughtTogether->first();
            $bundlePrice = $product->effective_selling_price + $bundleItem->effective_selling_price;
        @endphp
        <div class="rounded-3xl bg-slate-50 border border-slate-200 p-6 sm:p-8 space-y-6">
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Frequently Bought Together</h2>
                <p class="text-xs text-slate-500">Customers who bought this product also frequently bundled with:</p>
            </div>

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <!-- Products in Bundle -->
                <div class="flex items-center gap-4 flex-wrap">
                    <!-- Main Product -->
                    <div class="flex items-center gap-3">
                        <div class="w-16 h-16 rounded-xl bg-white border border-slate-200 p-1 aspect-square shrink-0">
                            <img src="{{ $product->primaryImage?->public_url ?? $currentMainImage }}" alt="{{ $product->title }}" class="w-full h-full object-contain"/>
                        </div>
                        <div class="max-w-[180px]">
                            <div class="text-xs font-bold text-slate-900 truncate">{{ $product->title }}</div>
                            <div class="text-xs font-black text-slate-800">৳{{ number_format($product->effective_selling_price, 0) }}</div>
                        </div>
                    </div>

                    <span class="text-2xl font-bold text-slate-400">+</span>

                    <!-- Secondary Bundle Item -->
                    <div class="flex items-center gap-3">
                        <div class="w-16 h-16 rounded-xl bg-white border border-slate-200 p-1 aspect-square shrink-0">
                            <img src="{{ $bundleItem->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=200' }}" alt="{{ $bundleItem->title }}" class="w-full h-full object-contain"/>
                        </div>
                        <div class="max-w-[180px]">
                            <div class="text-xs font-bold text-slate-900 truncate">{{ $bundleItem->title }}</div>
                            <div class="text-xs font-black text-slate-800">৳{{ number_format($bundleItem->effective_selling_price, 0) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Combined Price & One-Click Add Bundle -->
                <div class="flex items-center gap-4 shrink-0 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                    <div>
                        <div class="text-[11px] text-slate-500 font-semibold">Total Bundle Price:</div>
                        <div class="text-xl font-black text-indigo-700">৳{{ number_format($bundlePrice, 0) }}</div>
                    </div>
                    <button
                        type="button"
                        wire:click="addBundleToCart({{ $bundleItem->id }})"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs tracking-wide shadow-xs transition"
                    >
                        Add Both to Cart
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ================= PRODUCT INFORMATION TABS ================= -->
    <div class="rounded-3xl bg-white border border-slate-200 overflow-hidden shadow-xs">
        <!-- Tabs Header Navigation -->
        <div class="flex items-center border-b border-slate-200 bg-slate-50/70 overflow-x-auto no-scrollbar text-xs font-bold">
            <button
                type="button"
                wire:click="$set('activeTab', 'specifications')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'specifications' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Technical Specifications
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'features')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'features' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Key Features
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'description')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'description' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Full Description
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'warranty')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'warranty' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Warranty & Service
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'delivery')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'delivery' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Delivery & Returns
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'reviews')"
                class="px-6 py-4 border-b-2 transition shrink-0 cursor-pointer {{ $activeTab === 'reviews' ? 'border-indigo-600 text-indigo-600 bg-white' : 'border-transparent text-slate-600 hover:text-slate-900' }}"
            >
                Customer & Buyer Reviews ({{ $totalReviews }})
            </button>
        </div>

        <!-- Tab 1: TECHNICAL SPECIFICATIONS (Grouped Table Standard) -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'specifications'">
            <div class="space-y-8 max-w-4xl">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Technical Specifications</h3>
                    <p class="text-xs text-slate-500">Verified factory technical specifications for model {{ $product->model_number ?? $product->sku }}</p>
                </div>

                @if (!empty($product->specifications) && is_array($product->specifications))
                    <div class="space-y-6">
                        @foreach ($product->specifications as $groupTitle => $groupItems)
                            <div class="border border-slate-200 rounded-2xl overflow-hidden">
                                <div class="bg-slate-100/80 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-slate-800 border-b border-slate-200">
                                    {{ $groupTitle }}
                                </div>
                                <div class="divide-y divide-slate-100 text-xs">
                                    @if (is_array($groupItems))
                                        @foreach ($groupItems as $key => $val)
                                            <div class="grid grid-cols-1 sm:grid-cols-3 p-3 hover:bg-slate-50/50 transition">
                                                <div class="font-semibold text-slate-600">{{ $key }}</div>
                                                <div class="sm:col-span-2 text-slate-900 font-medium">{{ is_array($val) ? implode(', ', $val) : $val }}</div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="p-3 text-slate-800">{{ $groupItems }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200 text-xs text-slate-600 leading-relaxed">
                        Standard retail specifications apply. Please refer to description and product packaging for detailed user parameters.
                    </div>
                @endif
            </div>
        </div>

        <!-- Tab 2: KEY FEATURES -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'features'">
            <div class="space-y-6 max-w-4xl">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Key Features & Highlights</h3>
                    <p class="text-xs text-slate-500">Primary architectural innovations and features</p>
                </div>

                @if (!empty($product->features) && is_array($product->features))
                    <ul class="space-y-3 text-xs text-slate-800">
                        @foreach ($product->features as $feature)
                            <li class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 border border-slate-100">
                                <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">✓</span>
                                <span class="leading-relaxed">{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-xs text-slate-600">{{ $product->short_description }}</p>
                @endif
            </div>
        </div>

        <!-- Tab 3: FULL DESCRIPTION -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'description'">
            <div class="max-w-4xl space-y-4">
                <h3 class="text-base font-extrabold text-slate-900">Product Overview</h3>
                <div class="prose prose-slate prose-sm text-xs leading-relaxed text-slate-700 space-y-3">
                    <p>{{ $product->full_description ?? $product->short_description }}</p>
                </div>
            </div>
        </div>

        <!-- Tab 4: WARRANTY & SERVICE -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'warranty'">
            <div class="max-w-3xl space-y-6">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Official Warranty Policy</h3>
                    <p class="text-xs text-slate-500">Authorized manufacturer coverage and terms</p>
                </div>

                <div class="p-5 rounded-2xl bg-indigo-50/50 border border-indigo-100 space-y-3 text-xs">
                    <div class="font-bold text-indigo-900 text-sm">
                        🛡️ {{ $product->warranty_period ?? '1 Year Standard Warranty' }}
                    </div>
                    <p class="text-slate-700 leading-relaxed">
                        {{ $product->warranty_policy ?? 'All products are covered by manufacturer warranty against defects in materials and workmanship. Warranty claims are serviced nationwide at authorized service centers.' }}
                    </p>
                    <div class="text-[11px] text-slate-500 pt-2 border-t border-indigo-100">
                        Please retain your order invoice and warranty card delivered with the consignment.
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 5: DELIVERY & RETURNS -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'delivery'">
            <div class="max-w-3xl space-y-6">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Shipping & Delivery Information</h3>
                    <p class="text-xs text-slate-500">Logistics dispatch timelines and return window</p>
                </div>

                <div class="space-y-4 text-xs text-slate-700">
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <div class="font-bold text-slate-900">Inside Dhaka Metropolitan</div>
                        <p>Standard delivery within 24 hours. Express same-day delivery available for orders confirmed before 12 PM.</p>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <div class="font-bold text-slate-900">Nationwide (All 64 Districts)</div>
                        <p>Shipped via Steadfast, eCourier, and SA Paribahan. Delivery timeline: 2 to 3 business days.</p>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <div class="font-bold text-slate-900">7-Day Replacement Policy</div>
                        <p>Returns accepted if product is defective, damaged in transit, or incorrect. Seals and packaging must remain intact.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 6: CUSTOMER REVIEWS & RATINGS -->
        <div class="p-6 sm:p-10" x-show="$wire.activeTab === 'reviews'">
            <div class="space-y-8">
                <!-- Breakdown & Form -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <div class="lg:col-span-5 bg-slate-50 rounded-2xl p-6 border border-slate-200 space-y-5">
                        <div class="flex items-center gap-5">
                            <div class="text-center">
                                <div class="text-5xl font-black text-slate-900 leading-none">
                                    {{ $averageRating > 0 ? number_format($averageRating, 1) : '0.0' }}
                                </div>
                                <div class="text-xs text-slate-500 font-medium mt-1">out of 5.0</div>
                            </div>
                            <div>
                                <div class="flex items-center text-amber-400 text-lg">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <span>{{ $i <= round($averageRating) ? '★' : '☆' }}</span>
                                    @endfor
                                </div>
                                <div class="text-xs font-semibold text-slate-700 mt-1">
                                    {{ $totalReviews }} verified ratings
                                </div>
                            </div>
                        </div>

                        <!-- Star Bars -->
                        <div class="space-y-2 pt-2 border-t border-slate-200 text-xs">
                            @foreach ([5, 4, 3, 2, 1] as $star)
                                <div class="flex items-center gap-3">
                                    <button type="button" wire:click="setFilterRating('{{ $star }}')" class="w-12 text-left font-semibold text-slate-600 hover:text-indigo-600 flex items-center gap-1">
                                        <span>{{ $star }}</span>
                                        <span class="text-amber-400">★</span>
                                    </button>
                                    <div class="flex-1 h-2 rounded-full bg-slate-200 overflow-hidden">
                                        <div class="h-full bg-amber-400 rounded-full" style="width: {{ $ratingDistribution[$star]['percentage'] }}%"></div>
                                    </div>
                                    <span class="w-8 text-right text-slate-400 font-mono text-[11px]">
                                        {{ $ratingDistribution[$star]['count'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Write Review Column -->
                    <div class="lg:col-span-7">
                        @auth
                            <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                                <h4 class="text-sm font-bold text-slate-900">Write a Customer Review</h4>
                                @if ($reviewSuccessMessage)
                                    <div class="p-3 rounded-xl bg-emerald-50 text-emerald-800 text-xs font-semibold">
                                        {{ $reviewSuccessMessage }}
                                    </div>
                                @endif
                                @if ($reviewErrorMessage)
                                    <div class="p-3 rounded-xl bg-rose-50 text-rose-800 text-xs font-semibold">
                                        {{ $reviewErrorMessage }}
                                    </div>
                                @endif

                                <form wire:submit="submitReview" class="space-y-3 text-xs">
                                    <div>
                                        <label class="block font-semibold text-slate-700 mb-1">Your Rating *</label>
                                        <select wire:model="reviewRating" class="py-2 px-3 border border-slate-300 rounded-xl text-xs font-bold">
                                            <option value="5">★★★★★ 5 Stars (Exceptional)</option>
                                            <option value="4">★★★★☆ 4 Stars (Very Good)</option>
                                            <option value="3">★★★☆☆ 3 Stars (Average)</option>
                                            <option value="2">★★☆☆☆ 2 Stars (Below Expectation)</option>
                                            <option value="1">★☆☆☆☆ 1 Star (Poor)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block font-semibold text-slate-700 mb-1">Review Headline *</label>
                                        <input type="text" wire:model="reviewTitle" placeholder="e.g. Unmatched 4K Picture Quality" class="w-full py-2 px-3 border border-slate-300 rounded-xl"/>
                                    </div>
                                    <div>
                                        <label class="block font-semibold text-slate-700 mb-1">Detailed Feedback *</label>
                                        <textarea rows="3" wire:model="reviewComment" placeholder="Share your experience regarding delivery, build quality, and performance..." class="w-full py-2 px-3 border border-slate-300 rounded-xl"></textarea>
                                    </div>
                                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold transition shadow-xs">
                                        Submit Review
                                    </button>
                                </form>
                            </div>
                        @else
                            <div class="p-8 text-center rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                                <p class="text-xs text-slate-600">Please sign in to write a verified customer review for this product.</p>
                                <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700 transition">
                                    Sign In to Review
                                </a>
                            </div>
                        @endauth
                    </div>
                </div>

                <!-- Reviews Feed -->
                @if ($reviews->isNotEmpty())
                    <div class="space-y-4 pt-6 border-t border-slate-200">
                        @foreach ($reviews as $rev)
                            <div class="p-5 rounded-2xl bg-white border border-slate-200 space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="font-bold text-xs text-slate-900">{{ $rev->user?->name ?? 'Verified Buyer' }}</div>
                                        @if ($rev->is_verified_purchase)
                                            <span class="px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">Verified Purchase</span>
                                        @endif
                                    </div>
                                    <div class="text-amber-400 text-xs">
                                        @for ($i = 1; $i <= 5; $i++)
                                            <span>{{ $i <= $rev->rating ? '★' : '☆' }}</span>
                                        @endfor
                                    </div>
                                </div>
                                <div class="text-xs font-bold text-slate-800">{{ $rev->title }}</div>
                                <p class="text-xs text-slate-600 leading-relaxed">{{ $rev->comment }}</p>
                                @if ($rev->seller_reply)
                                    <div class="mt-2 p-3 rounded-xl bg-indigo-50/70 border border-indigo-100 text-xs space-y-1">
                                        <div class="font-bold text-indigo-900 text-[11px]">Factory Response from {{ $product->seller?->sellerProfile?->store_name }}</div>
                                        <p class="text-indigo-900/80">{{ $rev->seller_reply }}</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- ================= RELATED PRODUCTS IN SAME CATEGORY ================= -->
    @if ($relatedProducts->isNotEmpty())
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">Related Products</h2>
                    <p class="text-xs text-slate-500">More options from {{ $product->category?->name }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ($relatedProducts as $rel)
                    <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs hover:border-indigo-400 hover:shadow-md transition flex flex-col justify-between group">
                        <div>
                            <div class="rounded-xl overflow-hidden bg-slate-100 aspect-square mb-2.5">
                                <a href="{{ route('catalog.show', $rel->slug) }}">
                                    <img src="{{ $rel->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=400' }}" alt="{{ $rel->title }}" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"/>
                                </a>
                            </div>
                            <div class="text-[10px] font-bold text-indigo-600 uppercase">{{ $rel->brand?->name ?? 'Direct' }}</div>
                            <h3 class="text-xs font-bold text-slate-900 line-clamp-2 mt-1">
                                <a href="{{ route('catalog.show', $rel->slug) }}" class="hover:text-indigo-600 transition">{{ $rel->title }}</a>
                            </h3>
                        </div>
                        <div class="pt-2 border-t border-slate-100 mt-2 flex items-baseline justify-between">
                            <span class="text-xs font-black text-slate-900">৳{{ number_format($rel->effective_selling_price, 0) }}</span>
                            <a href="{{ route('catalog.show', $rel->slug) }}" class="text-[11px] font-bold text-indigo-600 hover:underline">View</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- ================= STICKY MOBILE PURCHASE BOTTOM BAR ================= -->
    <div class="fixed bottom-0 left-0 right-0 z-30 lg:hidden bg-white/95 backdrop-blur-md border-t border-slate-200 p-3 shadow-2xl">
        <div class="flex items-center justify-between gap-3 max-w-lg mx-auto">
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-slate-900 truncate">{{ $product->title }}</div>
                <div class="text-sm font-black text-slate-900">৳{{ number_format($product->effective_selling_price, 0) }}</div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button
                    type="button"
                    wire:click="addToCartRetail"
                    class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs shadow-xs"
                >
                    Add
                </button>
                <button
                    type="button"
                    wire:click="buyNowRetail"
                    class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold text-xs shadow-xs"
                >
                    Buy Now
                </button>
            </div>
        </div>
    </div>

    <!-- ================= FULLSCREEN IMAGE GALLERY MODAL ================= -->
    @if ($showFullscreenGallery)
        <div class="fixed inset-0 z-50 bg-black/95 flex flex-col items-center justify-between p-6">
            <div class="w-full flex justify-between items-center text-white text-xs">
                <div>Image {{ $activeImageIndex + 1 }} of {{ count($galleryImages) }}</div>
                <button type="button" wire:click="toggleFullscreenGallery" class="p-2 rounded-full bg-white/10 hover:bg-white/20 text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="max-w-4xl max-h-[75vh] w-full flex items-center justify-center p-4">
                <img src="{{ $currentMainImage }}" alt="{{ $product->title }}" class="max-h-[75vh] max-w-full object-contain"/>
            </div>

            <div class="flex items-center gap-3 overflow-x-auto py-2">
                @foreach ($galleryImages as $idx => $img)
                    <button
                        type="button"
                        wire:click="selectImage({{ $idx }})"
                        class="w-16 h-16 rounded-xl border-2 transition shrink-0 overflow-hidden {{ $activeImageIndex === $idx ? 'border-amber-400 ring-2 ring-amber-200' : 'border-white/20' }}"
                    >
                        <img src="{{ $img }}" alt="Thumbnail {{ $idx + 1 }}" class="w-full h-full object-contain bg-white/5"/>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <!-- ================= RFQ SUBMISSION MODAL ================= -->
    @if ($showRfqModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-200 relative">
                <button wire:click="closeRfqModal" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                @if ($rfqSuccess)
                    <div class="text-center py-6 space-y-4">
                        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h3 class="text-xl font-black text-slate-900">RFQ Inquiry Submitted!</h3>
                        <p class="text-xs text-slate-600 max-w-sm mx-auto leading-relaxed">
                            Your custom quotation request for <strong class="text-slate-900">{{ $product->title }}</strong> has been dispatched to <strong>{{ $product->seller?->sellerProfile?->store_name }}</strong>.
                        </p>
                        <button wire:click="closeRfqModal" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition">
                            Close & Continue
                        </button>
                    </div>
                @else
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">
                            B2B RFQ Inquiry
                        </span>
                        <h2 class="text-xl font-black text-slate-900 mt-1">Request Factory Quotation</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Supplier: <strong class="text-indigo-600">{{ $product->seller?->sellerProfile?->store_name }}</strong></p>
                    </div>

                    <form wire:submit="submitRfq" class="space-y-4 text-xs">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Target Quantity (Units) *</label>
                                <input type="number" min="1" wire:model="rfqTargetQuantity" placeholder="e.g. 500" class="w-full py-2 px-3 border border-slate-300 rounded-xl font-bold focus:ring-2 focus:ring-indigo-500"/>
                                @error('rfqTargetQuantity') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Target Price / Unit (৳ Tk)</label>
                                <input type="number" step="0.01" wire:model="rfqTargetPrice" placeholder="Your budget in ৳" class="w-full py-2 px-3 border border-slate-300 rounded-xl font-bold focus:ring-2 focus:ring-indigo-500"/>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Your Name *</label>
                                <input type="text" wire:model="rfqBuyerName" class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                                @error('rfqBuyerName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Phone Number *</label>
                                <input type="text" wire:model="rfqBuyerPhone" placeholder="+88017..." class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                                @error('rfqBuyerPhone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Company / Store Name</label>
                                <input type="text" wire:model="rfqCompanyName" placeholder="e.g. Retailer or Store Name" class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Destination District *</label>
                                <input type="text" wire:model="rfqDestinationDistrict" placeholder="e.g. Dhaka, Chittagong" class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                            </div>
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Custom Specifications / Notes</label>
                            <textarea rows="3" wire:model="rfqSpecifications" placeholder="Delivery timeline, custom branding, or volume packaging requirements..." class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>
                        <div class="pt-2 flex items-center justify-end gap-3">
                            <button type="button" wire:click="closeRfqModal" class="px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100 font-semibold">Cancel</button>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold transition shadow-sm">Send Inquiry to Supplier</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
